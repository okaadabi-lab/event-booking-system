<?php

declare(strict_types=1);

namespace App\Customer;

use App\Support\Database;
use App\Support\Row;

final class CustomerRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Phone is the natural key: the same person sending a second inquiry reuses
     * their record. An existing name is never overwritten from a public form;
     * a missing email is filled in.
     */
    public function findOrCreate(string $name, string $phone, ?string $email): Customer
    {
        // Insert-or-keep instead of select-then-insert: two first inquiries from the
        // same phone arriving together both land on one row instead of one of them
        // failing on the unique key.
        $this->db->run(
            'INSERT INTO customers (name, phone, email) VALUES (:name, :phone, :email)
             ON DUPLICATE KEY UPDATE id = id',
            ['name' => $name, 'phone' => $phone, 'email' => $email]
        );
        $row = $this->db->fetchOne('SELECT * FROM customers WHERE phone = :phone FOR UPDATE', ['phone' => $phone])
            ?? throw new \RuntimeException('Customer row vanished after insert.');

        $id = Row::int($row, 'id');
        $existingEmail = Row::nullableString($row, 'email');
        if ($existingEmail === null && $email !== null) {
            $this->db->run('UPDATE customers SET email = :email WHERE id = :id', ['email' => $email, 'id' => $id]);
            $existingEmail = $email;
        }

        return new Customer($id, Row::string($row, 'name'), $phone, $existingEmail);
    }
}

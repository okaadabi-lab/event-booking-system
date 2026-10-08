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
        $row = $this->db->fetchOne('SELECT * FROM customers WHERE phone = :phone FOR UPDATE', ['phone' => $phone]);
        if ($row !== null) {
            $id = Row::int($row, 'id');
            $existingEmail = Row::nullableString($row, 'email');
            if ($existingEmail === null && $email !== null) {
                $this->db->run('UPDATE customers SET email = :email WHERE id = :id', ['email' => $email, 'id' => $id]);
                $existingEmail = $email;
            }

            return new Customer($id, Row::string($row, 'name'), $phone, $existingEmail);
        }

        $id = $this->db->insert(
            'INSERT INTO customers (name, phone, email) VALUES (:name, :phone, :email)',
            ['name' => $name, 'phone' => $phone, 'email' => $email]
        );

        return new Customer($id, $name, $phone, $email);
    }
}

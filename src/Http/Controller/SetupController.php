<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Activity\ActivityLog;
use App\Auth\Ability;
use App\Auth\Policy;
use App\Auth\Role;
use App\Auth\UserRepository;
use App\Catalog\CatalogRepository;
use App\Http\RequestContext;
use App\Http\View;
use App\Resource\ResourceRepository;
use App\Resource\ResourceType;
use App\Support\DomainRuleViolation;
use App\Support\FormInput;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Owner-only management of packages, add-ons, resources and staff accounts.
 * Each form posts an optional "id": present means update, absent means create.
 */
final class SetupController
{
    public function __construct(
        private readonly View $view,
        private readonly CatalogRepository $catalog,
        private readonly ResourceRepository $resources,
        private readonly UserRepository $users,
        private readonly ActivityLog $activity,
        private readonly Policy $policy,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::user($request);
        $this->policy->authorize($user, Ability::ManageCatalog);
        $tab = RequestContext::query($request)['tab'] ?? 'packages';

        return $this->view->render($request, $response, 'setup/index.twig', [
            'tab' => in_array($tab, ['packages', 'addons', 'resources', 'users'], true) ? $tab : 'packages',
            'packages' => $this->catalog->packages(),
            'addons' => $this->catalog->addons(),
            'resources' => $this->resources->all(),
            'users' => $this->users->all(),
            'roles' => Role::cases(),
            'resource_types' => ResourceType::cases(),
        ]);
    }

    public function savePackage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $actor = RequestContext::user($request);
        $this->policy->authorize($actor, Ability::ManageCatalog);
        $input = new FormInput(RequestContext::body($request));
        $id = $input->int('id', 'Package', 1, PHP_INT_MAX, false);
        $name = $input->string('name', 'Name', true, 120, 2);
        $description = $input->string('description', 'Description', false, 500) ?? '';
        $price = $input->money('price', 'Price');
        if ($name !== null && $this->catalog->nameTaken('package', $name, $id ?? 0)) {
            $input->addError('name', 'Another package already has this name.');
        }
        $input->throwIfInvalid();
        assert($name !== null && $price !== null);

        if ($id === null) {
            $id = $this->catalog->createPackage($name, $description, $price);
            $this->activity->record('package', $id, 'created', "Package $name created", $actor->id, null, ['price' => $price]);
        } else {
            $before = $this->catalog->find('package', $id) ?? throw new DomainRuleViolation('Package not found.');
            $active = $input->checkbox('is_active');
            $this->catalog->updatePackage($id, $name, $description, $price, $active);
            $this->activity->record(
                'package',
                $id,
                'updated',
                "Package $name updated",
                $actor->id,
                ['name' => $before->name, 'price' => $before->price, 'active' => $before->isActive],
                ['name' => $name, 'price' => $price, 'active' => $active]
            );
        }
        RequestContext::session($request)->flash('success', "Package \"$name\" saved. Existing quotes keep their original prices.");

        return View::redirect($response, '/setup?tab=packages');
    }

    public function saveAddon(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $actor = RequestContext::user($request);
        $this->policy->authorize($actor, Ability::ManageCatalog);
        $input = new FormInput(RequestContext::body($request));
        $id = $input->int('id', 'Add-on', 1, PHP_INT_MAX, false);
        $name = $input->string('name', 'Name', true, 120, 2);
        $unit = $input->string('unit_label', 'Unit', true, 30);
        $price = $input->money('price', 'Price');
        if ($name !== null && $this->catalog->nameTaken('addon', $name, $id ?? 0)) {
            $input->addError('name', 'Another add-on already has this name.');
        }
        $input->throwIfInvalid();
        assert($name !== null && $unit !== null && $price !== null);

        if ($id === null) {
            $id = $this->catalog->createAddon($name, $unit, $price);
            $this->activity->record('addon', $id, 'created', "Add-on $name created", $actor->id, null, ['price' => $price]);
        } else {
            $before = $this->catalog->find('addon', $id) ?? throw new DomainRuleViolation('Add-on not found.');
            $active = $input->checkbox('is_active');
            $this->catalog->updateAddon($id, $name, $unit, $price, $active);
            $this->activity->record(
                'addon',
                $id,
                'updated',
                "Add-on $name updated",
                $actor->id,
                ['name' => $before->name, 'price' => $before->price, 'active' => $before->isActive],
                ['name' => $name, 'price' => $price, 'active' => $active]
            );
        }
        RequestContext::session($request)->flash('success', "Add-on \"$name\" saved.");

        return View::redirect($response, '/setup?tab=addons');
    }

    public function saveResource(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $actor = RequestContext::user($request);
        $this->policy->authorize($actor, Ability::ManageCatalog);
        $input = new FormInput(RequestContext::body($request));
        $id = $input->int('id', 'Resource', 1, PHP_INT_MAX, false);
        $name = $input->string('name', 'Name', true, 120, 2);
        $capacity = $input->int('capacity', 'Capacity', 1, 100_000, false);
        $type = $id === null ? $input->enum('type', 'resource type', ResourceType::class) : null;
        if ($name !== null && $this->resources->nameTaken($name, $id ?? 0)) {
            $input->addError('name', 'Another resource already has this name.');
        }
        $input->throwIfInvalid();
        assert($name !== null);

        if ($id === null) {
            assert($type instanceof ResourceType);
            $id = $this->resources->create($name, $type, $capacity);
            $this->activity->record('resource', $id, 'created', "Resource $name created", $actor->id);
        } else {
            $this->resources->find($id) ?? throw new DomainRuleViolation('Resource not found.');
            $this->resources->update($id, $name, $capacity, $input->checkbox('is_active'));
            $this->activity->record('resource', $id, 'updated', "Resource $name updated", $actor->id);
        }
        RequestContext::session($request)->flash('success', "Resource \"$name\" saved.");

        return View::redirect($response, '/setup?tab=resources');
    }

    public function saveUser(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $actor = RequestContext::user($request);
        $this->policy->authorize($actor, Ability::ManageUsers);
        $input = new FormInput(RequestContext::body($request));
        $id = $input->int('id', 'User', 1, PHP_INT_MAX, false);
        $name = $input->string('name', 'Name', true, 100, 2);
        $email = $input->email('email', 'Email');
        $role = $input->enum('role', 'role', Role::class);
        $password = $input->raw('password');
        if ($id === null || $password !== '') {
            if (mb_strlen($password) < 12) {
                $input->addError('password', 'Use a password of at least 12 characters.');
            }
        }
        if ($email !== null && $this->users->emailTaken($email, $id)) {
            $input->addError('email', 'Another account already uses this email.');
        }
        $input->throwIfInvalid();
        assert($name !== null && $email !== null && $role instanceof Role);

        if ($id === null) {
            $id = $this->users->create($name, $email, $role, $password);
            $this->activity->record('user', $id, 'created', "User $email created as {$role->value}", $actor->id);
        } else {
            $before = $this->users->find($id) ?? throw new DomainRuleViolation('User not found.');
            $active = $input->checkbox('is_active');
            $losesOwner = $before->role === Role::Owner && $before->isActive && ($role !== Role::Owner || !$active);
            if ($losesOwner && $this->users->countActiveOwners() <= 1) {
                throw new DomainRuleViolation('Keep at least one active owner account.');
            }
            $this->users->update($id, $name, $email, $role, $active);
            if ($password !== '') {
                $this->users->setPassword($id, $password);
            }
            $this->activity->record(
                'user',
                $id,
                'updated',
                "User $email updated",
                $actor->id,
                ['role' => $before->role->value, 'active' => $before->isActive],
                ['role' => $role->value, 'active' => $active, 'password_changed' => $password !== '']
            );
        }
        RequestContext::session($request)->flash('success', "Account for $name saved.");

        return View::redirect($response, '/setup?tab=users');
    }
}

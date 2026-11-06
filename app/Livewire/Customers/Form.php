<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Money;
use App\Services\CustomerService;
use App\Services\SettingsService;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Add or edit a customer: the old customers/new, customers/[id]/edit and
 * customer-form.tsx, with the old customer.actions parsing. A blank credit
 * limit means "no limit"; an explicit 0 means "no credit at all".
 */
final class Form extends Component
{
    #[Locked]
    public ?int $customerId = null;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $notes = '';

    public string $creditLimit = '';

    public function mount(?int $id = null): void
    {
        if ($id === null) {
            return;
        }
        try {
            $customer = CustomerService::getCustomer($id);
        } catch (NotFoundError) {
            abort(404);
        }
        $this->customerId = $id;
        $this->name = $customer['name'];
        $this->phone = $customer['phone'] ?? '';
        $this->email = $customer['email'] ?? '';
        $this->address = $customer['address'] ?? '';
        $this->notes = $customer['notes'] ?? '';
        $this->creditLimit = $customer['creditLimit'] === null ? '' : Money::toInputString($customer['creditLimit']);
    }

    public function save(): void
    {
        $this->authorize($this->customerId === null ? 'customers.create' : 'customers.edit');
        $this->name = trim($this->name);
        $this->validate([
            'name' => ['required', 'max:120'],
            'phone' => ['max:40'],
            'email' => ['max:120'],
            'address' => ['max:300'],
            'notes' => ['max:500'],
        ], ['name.required' => 'Enter the customer’s name.']);

        try {
            $limit = trim($this->creditLimit) === '' ? null : Money::parse($this->creditLimit);
        } catch (DomainError $e) {
            $this->addError('creditLimit', $e->userMessage);

            return;
        }

        $input = [
            'name' => $this->name, 'phone' => trim($this->phone), 'email' => trim($this->email),
            'address' => trim($this->address), 'notes' => trim($this->notes), 'creditLimit' => $limit,
        ];
        $actor = (Principal::load((int) auth()->id()) ?? abort(403))->actor();
        try {
            if ($this->customerId === null) {
                $id = CustomerService::createCustomer($input, $actor);
                session()->flash('status', 'Customer added.');
            } else {
                CustomerService::updateCustomer($this->customerId, $input, $actor);
                $id = $this->customerId;
                session()->flash('status', 'Customer updated.');
            }
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }
        $this->redirect("/customers/{$id}");
    }

    public function render(): View
    {
        return view('livewire.customers.form', ['currency' => SettingsService::get()->currency_code ?? 'GHS'])
            ->title($this->customerId === null ? 'Add customer' : 'Edit customer');
    }
}

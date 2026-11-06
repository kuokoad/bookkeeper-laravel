<?php

declare(strict_types=1);

namespace App\Livewire\Suppliers;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Services\SupplierService;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The old suppliers/new and suppliers/[id]/edit pages with their shared
 * supplier-form.tsx: add a supplier (no id) or change one.
 */
final class Form extends Component
{
    #[Locked]
    public ?int $supplierId = null;

    #[Locked]
    public string $supplierName = '';

    public string $name = '';

    public string $contactPerson = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $notes = '';

    public function mount(?string $id = null): void
    {
        if ($id === null) {
            return;
        }
        try {
            $supplier = SupplierService::getSupplier((int) $id);
        } catch (NotFoundError) {
            abort(404);
        }
        $this->supplierId = $supplier['id'];
        $this->supplierName = $supplier['name'];
        foreach (['name', 'contactPerson', 'phone', 'email', 'address', 'notes'] as $field) {
            $this->{$field} = (string) ($supplier[$field] ?? '');
        }
    }

    public function save(): void
    {
        $this->authorize($this->supplierId === null ? 'suppliers.create' : 'suppliers.edit');
        // The old zod schema: required name and the column lengths.
        $this->validate([
            'name' => 'required|string|max:120',
            'contactPerson' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:300',
            'notes' => 'nullable|string|max:500',
        ], ['name.required' => 'Enter the supplier’s name.']);

        $input = ['name' => $this->name, 'contactPerson' => $this->contactPerson, 'phone' => $this->phone, 'email' => $this->email, 'address' => $this->address, 'notes' => $this->notes];
        $actor = Principal::load((int) auth()->id())->actor();
        try {
            if ($this->supplierId === null) {
                $id = SupplierService::createSupplier($input, $actor);
            } else {
                SupplierService::updateSupplier($this->supplierId, $input, $actor);
                $id = $this->supplierId;
            }
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }

        session()->flash('status', $this->supplierId === null ? 'Supplier added.' : 'Supplier updated.');
        $this->redirect("/suppliers/{$id}");
    }

    public function render(): View
    {
        return view('livewire.suppliers.form')->title($this->supplierId === null ? 'Add supplier' : 'Edit supplier');
    }
}

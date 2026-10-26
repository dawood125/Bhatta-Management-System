<?php

namespace App\Actions\Settings;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Models\Kiln;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sales emails and invoices (kiln profile): whether each new sale's invoice is emailed, to which
 * addresses (comma-separated, at most 5), and a note printed at the bottom of every invoice
 * (terms, bank details, ...).
 */
class SaveSalesSettings
{
    use ValidatesSettings;

    public const MAX_ADDRESSES = 5;

    /**
     * @param  array<string, mixed>  $input  sales_email_enabled, sales_email_to, invoice_note
     */
    public function handle(User $actor, Kiln $kiln, array $input): Kiln
    {
        $this->authorizeSettings($actor, Permission::KilnProfileManage);

        if ($kiln->id !== $this->kilnId()) {
            abort(404);
        }

        $input = $this->clean($input, ['sales_email_to', 'invoice_note']);
        $input['sales_email_enabled'] = filter_var($input['sales_email_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $data = Validator::make($input, [
            'sales_email_enabled' => ['boolean'],
            'sales_email_to' => ['nullable', 'string', 'max:500'],
            'invoice_note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'sales_email_to' => __('email addresses'),
            'invoice_note' => __('invoice note'),
        ])->validate();

        $addresses = array_values(array_unique(array_filter(array_map(fn (string $email) => mb_strtolower(trim($email)), explode(',', (string) $data['sales_email_to'])))));

        foreach ($addresses as $address) {
            if (Validator::make(['email' => $address], ['email' => ['email']])->fails()) {
                throw ValidationException::withMessages(['sales_email_to' => __('":address" is not a valid email address.', ['address' => $address])]);
            }
        }

        if (count($addresses) > self::MAX_ADDRESSES) {
            throw ValidationException::withMessages(['sales_email_to' => __('At most :max addresses.', ['max' => self::MAX_ADDRESSES])]);
        }

        if ($data['sales_email_enabled'] && $addresses === []) {
            throw ValidationException::withMessages(['sales_email_to' => __('Enter at least one address to email new sales to.')]);
        }

        $kiln->fill([
            'sales_email_enabled' => $data['sales_email_enabled'],
            'sales_email_to' => $addresses === [] ? null : implode(', ', $addresses),
            'invoice_note' => $data['invoice_note'],
        ])->save();

        return $kiln;
    }
}

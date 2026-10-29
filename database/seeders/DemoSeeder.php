<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Form;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a demo workspace with default roles, two users (different roles),
 * and an active field form with skip logic.
 *
 * Logins:  admin@example.org / password  (Owner)
 *          field@example.org / password  (Enumerator)
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Account::query()->exists()) {
            return;
        }

        $account = Account::create([
            'name' => 'AgriResearch West Africa',
            'slug' => 'agri-wa',
            'settings' => [
                'organization' => [
                    'name' => 'AgriResearch West Africa',
                    'contact_person' => 'Dr. A. Diallo',
                    'phone' => '+225 07 00 00 00 00',
                    'email' => 'contact@agri-wa.org',
                    'address' => 'Abidjan, Côte d\'Ivoire',
                    'website' => 'https://agri-wa.org',
                ],
                'field' => [
                    'project_name' => 'West African Crop Health Monitoring 2025',
                    'region' => 'Sud-Comoé region',
                    'notes' => 'Survey one plot per household per week. Take the photo before harvest. Offline entries save on the device and sync automatically when a connection is available.',
                ],
            ],
        ]);

        $owner = Role::create([
            'account_id' => $account->id,
            'name' => 'Owner',
            'permissions' => config('tenancy.default_roles.Owner'),
        ]);

        $enumerator = Role::create([
            'account_id' => $account->id,
            'name' => 'Enumerator',
            'permissions' => config('tenancy.default_roles.Enumerator'),
        ]);

        $admin = User::create([
            'name' => 'Demo Admin',
            'email' => 'admin@example.org',
            'password' => 'password',
        ]);

        $fieldOfficer = User::create([
            'name' => 'Awa Field Officer',
            'email' => 'field@example.org',
            'password' => 'password',
        ]);

        $admin->accounts()->attach($account->id, [
            'role_id' => $owner->id,
            'status' => 'active',
        ]);

        $fieldOfficer->accounts()->attach($account->id, [
            'role_id' => $enumerator->id,
            'status' => 'active',
        ]);

        Form::create([
            'account_id' => $account->id,
            'name' => 'Crop Health Survey',
            'slug' => 'crop-health',
            'description' => 'Weekly field assessment of maize / cassava plots',
            'status' => 'active',
            'version' => 1,
            'schema' => [
                'fields' => [
                    [
                        'name' => 'plot_code',
                        'label' => 'Plot code',
                        'type' => 'text',
                        'required' => true,
                        'help_text' => 'e.g. WA-014',
                    ],
                    [
                        'name' => 'crop',
                        'label' => 'Crop',
                        'type' => 'select',
                        'required' => true,
                        'options' => ['maize', 'cassava', 'millet', 'sorghum', 'other'],
                    ],
                    [
                        'name' => 'plot_size_ha',
                        'label' => 'Plot size (ha)',
                        'type' => 'number',
                    ],
                    [
                        'name' => 'has_disease',
                        'label' => 'Disease observed?',
                        'type' => 'select',
                        'options' => ['yes', 'no'],
                    ],
                    [
                        'name' => 'disease_name',
                        'label' => 'Disease name',
                        'type' => 'text',
                        'visible_if' => ['field' => 'has_disease', 'op' => 'eq', 'value' => 'yes'],
                    ],
                    [
                        'name' => 'yield_estimate',
                        'label' => 'Yield estimate (kg/ha)',
                        'type' => 'number',
                        'visible_if' => ['field' => 'has_disease', 'op' => 'eq', 'value' => 'no'],
                    ],
                    [
                        'name' => 'field_photo',
                        'label' => 'Field photo',
                        'type' => 'photo',
                        'help_text' => 'Photo of the crop / pest damage',
                    ],
                ],
            ],
        ]);
    }
}

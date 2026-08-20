<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Les 3 rôles fixes de l'ERP : admin (accès total, y compris gestion
     * des utilisateurs), manager (accès total au métier, pas aux
     * utilisateurs), viewer (lecture seule partout — c'est aussi le
     * comportement par défaut d'un utilisateur sans rôle du tout, cf.
     * HasRoleBasedAuthorization).
     */
    public const ROLES = ['admin', 'manager', 'viewer'];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        /*
         * Clause de sauvegarde ("grandfathering") : avant l'introduction
         * des rôles, TOUT utilisateur authentifié avait un accès total à
         * l'admin (aucune restriction). Pour ne priver personne d'accès
         * existant lors du déploiement de cette fonctionnalité, chaque
         * utilisateur déjà présent et n'ayant encore aucun rôle reçoit
         * automatiquement le rôle admin. Les nouveaux utilisateurs créés
         * après coup n'en bénéficient pas : ils démarrent sans rôle
         * (= lecteur) jusqu'à ce qu'un admin leur en assigne un.
         */
        User::doesntHave('roles')->get()->each(
            fn (User $user) => $user->assignRole('admin')
        );
    }
}

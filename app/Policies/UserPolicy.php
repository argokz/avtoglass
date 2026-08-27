<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canManageStaff();
    }

    public function view(User $user, User $target): bool
    {
        return $user->role->canManageStaff() || $user->is($target);
    }

    public function create(User $user): bool
    {
        return $user->role->canManageStaff();
    }

    public function update(User $user, User $target): bool
    {
        return $user->role->canManageStaff();
    }

    /**
     * Уволенного сотрудника снимают галочкой «активен», а не удалением:
     * на нём висят наряды, принятые наличные и выданные документы.
     */
    public function delete(User $user, User $target): bool
    {
        return false;
    }

    /**
     * Реестр отправлений: свой курьер видит свой, диспетчер — любой.
     *
     * В реестре весь маршрут смены — адреса, телефоны и суммы к получению
     * по каждому клиенту. Чужую смену открывать незачем.
     */
    public function printManifest(User $user, User $courier): bool
    {
        return $user->role->canWorkWithOrders() || $user->is($courier);
    }
}

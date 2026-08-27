<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Кто имеет право видеть заказ и печатать по нему документы.
 *
 * Документ — это не просто страница: в товарном чеке и счёте лежат телефон
 * клиента, адрес доставки и суммы. Пока маршрут был закрыт только `auth`,
 * любой сотрудник — включая курьера с чужого района — мог подставить в адрес
 * соседний номер заказа и прочитать чужие данные.
 */
class OrderPolicy
{
    /** Продажи ведут менеджер и админ — им видны все заявки. */
    public function viewAny(User $user): bool
    {
        return $user->role->canWorkWithOrders();
    }

    public function view(User $user, Order $order): bool
    {
        if ($user->role->canWorkWithOrders()) {
            return true;
        }

        // Курьеру и мастеру — только то, куда их назначили. Не «все заказы
        // на сегодня», а именно свои: наряд и есть основание видеть клиента.
        return $order->assignments()->where('assignee_id', $user->id)->exists();
    }

    /** Печать документов идёт по тому же правилу, что и просмотр. */
    public function printDocuments(User $user, Order $order): bool
    {
        return $this->view($user, $order);
    }

    public function update(User $user, Order $order): bool
    {
        return $user->role->canWorkWithOrders();
    }

    public function create(User $user): bool
    {
        return $user->role->canWorkWithOrders();
    }

    /**
     * Удалять заказы нельзя никому: заявка — это первичный документ,
     * у неё есть статус «Отменён», и он честнее исчезновения из базы.
     */
    public function delete(User $user, Order $order): bool
    {
        return false;
    }
}

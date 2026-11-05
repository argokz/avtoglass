<?php

return [
    /*
     * Content-Security-Policy. false — заголовок Report-Only: браузер
     * отчитывается о нарушениях, но ничего не блокирует. Включать после
     * чистого отчётного периода.
     */
    'csp_enforce' => (bool) env('CSP_ENFORCE', false),

    /*
     * Обязательная двухфакторная аутентификация для персонала панели.
     * Включать на бою: пароль почты — самое слабое место админки.
     */
    'staff_2fa_required' => (bool) env('STAFF_2FA_REQUIRED', false),

    /*
     * Срок хранения персональных данных закрытых заказов в месяцах.
     * После него команда pdn:anonymize заменяет контакты клиента на срез.
     */
    'pdn_retention_months' => (int) env('PDN_RETENTION_MONTHS', 36),
];

<?php

namespace App\Livewire\Preview\Concerns;

use App\Livewire\Preview\PreviewChatBook;
use App\Livewire\Preview\PreviewOrderBook;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** Interactive fake SMS only. Real OTP service and shared limits belong to stage 3. */
trait SignsIntoCabinet
{
    public string $loginPhone = '+77000000001';

    public string $loginCode = '';

    #[Locked]
    public string $loginState = 'phone';

    #[Locked]
    public string $loginMaskedPhone = '';

    #[Locked]
    public string $demoSmsCode = '';

    #[Locked]
    public int $resendAt = 0;

    #[Locked]
    public string $loginReturnScreen = 'orders';

    public function signedIn(): bool
    {
        return session()->has('modernization.preview_identity');
    }

    public function requestLoginCode(): void
    {
        $this->useLocale();
        $digits = preg_replace('/\D/', '', $this->loginPhone);
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7'.substr($digits, 1);
        }
        if (! preg_match('/^7\d{10}$/', $digits)) {
            throw ValidationException::withMessages(['loginPhone' => __('shop.login_phone_invalid')]);
        }
        $phone = '+'.$digits;
        $bucket = session('modernization.preview_sms.'.$digits, ['sent' => [], 'failed' => []]);
        $now = now()->timestamp;
        $bucket['sent'] = array_values(array_filter($bucket['sent'], fn ($time) => $time > $now - 86400));
        $bucket['failed'] = array_values(array_filter($bucket['failed'], fn ($time) => $time > $now - 900));
        $last = $bucket['sent'] ? max($bucket['sent']) : 0;
        if ($last > $now - 60 || count($bucket['sent']) >= 10
            || count(array_filter($bucket['sent'], fn ($time) => $time > $now - 3600)) >= 5 || count($bucket['failed']) >= 10) {
            throw ValidationException::withMessages(['loginPhone' => __('shop.login_rate_limit')]);
        }
        $sequence = (int) session('modernization.preview_sms_sequence', 0) + 1;
        session()->put('modernization.preview_sms_sequence', $sequence);
        $code = str_pad((string) (100000 + $sequence % 900000), 6, '0', STR_PAD_LEFT);
        $bucket['sent'][] = $now;
        session()->put('modernization.preview_sms.'.$digits, $bucket);
        session()->put('modernization.preview_challenge', [
            'phone' => $phone, 'hash' => hash_hmac('sha256', $code, config('app.key')),
            'expires' => $now + 300, 'attempts' => 0, 'demoSequence' => $sequence,
        ]);
        $this->loginPhone = $phone;
        $this->loginMaskedPhone = '+7 ••• ••• '.substr($digits, -4);
        $this->loginState = 'code';
        $this->demoSmsCode = $code; // Explicitly shown fake code on an isolated preview route.
        $this->resendAt = $now + 60;
        $this->loginCode = '';
        $this->resetValidation();
        $this->dispatch('login-code-ready');
    }

    public function verifyLoginCode(): void
    {
        $this->useLocale();
        $challenge = session('modernization.preview_challenge');
        if (! $challenge || $challenge['expires'] <= now()->timestamp) {
            throw ValidationException::withMessages(['loginCode' => __('shop.login_expired')]);
        }
        $key = 'modernization.preview_sms.'.substr($challenge['phone'], 1);
        $bucket = session($key, ['sent' => [], 'failed' => []]);
        $bucket['failed'] = array_values(array_filter($bucket['failed'], fn ($time) => $time > now()->timestamp - 900));
        if ($challenge['attempts'] >= 5 || count($bucket['failed']) >= 10) {
            throw ValidationException::withMessages(['loginCode' => __('shop.login_locked')]);
        }
        if (! preg_match('/^\d{6}$/', $this->loginCode)
            || ! hash_equals($challenge['hash'], hash_hmac('sha256', $this->loginCode, config('app.key')))) {
            $challenge['attempts']++;
            $bucket['failed'][] = now()->timestamp;
            session()->put('modernization.preview_challenge', $challenge);
            session()->put($key, $bucket);
            throw ValidationException::withMessages(['loginCode' => __('shop.login_wrong', ['count' => 5 - $challenge['attempts']])]);
        }
        session()->forget('modernization.preview_challenge');
        $previousOwner = (new PreviewOrderBook)->owner();
        $this->saveDraft();
        session()->regenerate();
        session()->put('modernization.preview_identity', $challenge['phone']);
        $this->reset('showManagerEditor', 'managerLines', 'managerAddress', 'managerSlot', 'managerFitmentChecked', 'managerEditorContext');
        (new PreviewOrderBook)->adoptGuestOrders();
        if ($previousOwner === 'guest') {
            $this->adoptGuestDraft();
            (new PreviewChatBook)->adoptGuest();
        } else {
            $this->restoreCurrentDraft();
        }
        $this->loginCode = '';
        $this->demoSmsCode = '';
        $this->loginState = 'phone';
        $this->proposal = null;
        $this->historyOwner = $challenge['phone'];
        $this->mountAssistant();
        $this->loadCustomerForms();
        $this->resetValidation();
        $this->screen = $this->loginReturnScreen;
        if ($this->historyOrder !== '') {
            $this->openHistoryOrder($this->historyOrder);
        }
    }

    public function changeLoginPhone(): void
    {
        session()->forget('modernization.preview_challenge');
        $this->loginState = 'phone';
        $this->loginCode = $this->demoSmsCode = '';
        $this->resetValidation();
    }

    private function restoreLoginChallenge(): void
    {
        $challenge = session('modernization.preview_challenge');
        if (! $challenge || $this->signedIn()) {
            return;
        }
        $this->loginPhone = $challenge['phone'];
        $this->loginMaskedPhone = '+7 ••• ••• '.substr($challenge['phone'], -4);
        $this->loginState = 'code';
        $this->demoSmsCode = (string) (100000 + $challenge['demoSequence'] % 900000);
        $sent = session('modernization.preview_sms.'.substr($challenge['phone'], 1).'.sent', []);
        $this->resendAt = ($sent ? max($sent) : 0) + 60;
    }

    public function simulateLoginError(string $kind): void
    {
        $this->useLocale();
        abort_unless(in_array($kind, ['delivery', 'expired'], true), 422);
        if ($kind === 'delivery') {
            session()->forget('modernization.preview_challenge');
            $this->loginState = 'phone';
            $this->demoSmsCode = '';
            throw ValidationException::withMessages(['loginPhone' => __('shop.login_delivery_failed')]);
        }
        $challenge = session('modernization.preview_challenge');
        abort_unless($challenge, 422);
        $challenge['expires'] = now()->subSecond()->timestamp;
        session()->put('modernization.preview_challenge', $challenge);
    }

    public function logoutCabinet(): void
    {
        $this->saveDraft();
        session()->forget(['modernization.preview_identity', 'modernization.preview_challenge']);
        session()->regenerate();
        $this->reset('demoOrder', 'proposal', 'proposalSequence', 'proposalConsent', 'proposalReply', 'changeText', 'showChange', 'editing', 'editingVersion');
        $this->reset('showManagerEditor', 'managerLines', 'managerAddress', 'managerSlot', 'managerFitmentChecked', 'managerEditorContext');
        $this->historyOwner = 'guest';
        $this->mountAssistant();
        $this->restoreCurrentDraft();
        $this->loadCustomerForms();
        $this->reset('templateName', 'templateEditingId');
        $this->historyOrder = '';
        $this->loginState = 'phone';
        $this->loginCode = $this->demoSmsCode = '';
        $this->screen = 'orders';
        $this->resetValidation();
    }
}

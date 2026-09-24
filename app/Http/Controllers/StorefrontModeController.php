<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StorefrontModeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['store', 'chat'])]]);
        abort_if($data['mode'] === 'chat' && ! settings()->aiChatEnabled(), 404);

        // Switching changes only the interface preference, never cart or conversation data.
        return redirect()->route($data['mode'] === 'chat' ? 'assistant' : 'home')
            ->withCookie(cookie('storefront_mode', $data['mode'], 60 * 24 * 180));
    }
}

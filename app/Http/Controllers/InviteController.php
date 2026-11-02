<?php

namespace App\Http\Controllers;

use App\Services\ReferralService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** "Invite friends": your link, how many joined and ordered, and what you both get. */
class InviteController extends Controller
{
    public function show(Request $request, ReferralService $referrals): View
    {
        return view('invite.show', ['invite' => $referrals->summary($request->user())]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Email address verification.
 *
 * The verification link is a signed URL, so the endpoint needs no
 * authentication: possession of a correctly signed link addressed to a
 * specific user is the proof.
 */
class EmailVerificationController extends Controller
{
    /**
     * Handle a click on the verification link.
     *
     * The route is signed, and the hash is checked against the user's current
     * address so an old link stops working once the address changes.
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::query()->find($id);

        if ($user === null || ! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            throw new AccessDeniedHttpException('This verification link is not valid.');
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'This address is already verified.']);
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        return response()->json(['message' => 'Email address verified.']);
    }

    /**
     * Send another verification email to the signed-in user.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'This address is already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email sent.']);
    }
}

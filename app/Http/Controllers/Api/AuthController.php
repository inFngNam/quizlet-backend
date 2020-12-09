<?php

namespace App\Http\Controllers\Api;

use App\Models\Members;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\VerifyAccount;
use App\Jobs\SendVerifyMail;
use Illuminate\Support\Facades\Redirect;

class AuthController extends Controller
{
public function signup(Request $request) {
        $this->validate(
            $request,
            [
                'username' => 'required | string |min:6| unique:users',
                'email' => 'required | string | email | unique:users',
                'password' => 'required|min:6|regex:/^.*(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])/',
                'birthday' => 'required|date|date_format:Y-m-d',
                'avatar' => 'file|max:4096'
            ],
            [
                'username.required' => 'Username fill can not be blank!',
                'email.required' => 'Email fill can not be blank!',
                'password.required' => 'Password fill can not be blank!',
                'password.min' => 'Password contain at least 6 characters, include uppercase, lowercase and number',
                'password.regex' => 'Password must be included uppercase, lowercase and number',
                'birthday.required' => 'Birthday can not be blank!',
                'avatar.max' => 'Maximum file size to upload is 4MB (4096 KB)'
            ]
        );
        if ($request->hasFile('avatar')) {
            $file = $request->avatar;
            $ext = '.' . $file->getClientOriginalExtension();
            $avatar = (int) getLastUserId() + 1 . $ext;
            $path = 'users/images/avatars';
            $file->move(base_path('public/' . $path), $avatar);
            $avatarPath = url($path) . '/' . $avatar;
        }
        $currentTime = getCurrentTime();
        $random = mt_rand(100000, 999999);
        $username = htmlspecialchars($request->username);
        $email = htmlspecialchars($request->email);
        $user_data = [
            'username' => $username,
            'email' => $email,
            'password' => bcrypt($request->password),
            'avatar' => isset($avatarPath) ? $avatarPath : null,
            'birthday' => date('Y-m-d', strtotime(htmlspecialchars($request->birthday))),
            'verified' => false,
            'verify_code' => $random,
            'created_at' => $currentTime,
            'updated_at' => $currentTime,
        ];
        try {
            $user = User::create($user_data);
            $link = '/verify/' . $user['verify_code'] . '/' . $user['email'];
            $this->dispatch(new SendVerifyMail($user['email'], $link));
            return response()->json($user,200);
        }
        catch (\Exception $exception) {
            return response()->json($exception->getMessage(), 500);
        }
    }

    public function login(Request $request) {
        $this->validate(
            $request,
            [
                'email' => 'required|string|email',
                'password' => 'required|string',
                'remember_me' => 'boolean'
            ],
            [
                'email.required' => 'Email can not be blank !',
                'password.required' => 'Password can not be blank !',
            ]
        );
        $checkVerified = User::where('email', $request->email)->get()->first();
        if ($checkVerified->verified == 1) {
            $credentials = request(['email', 'password']);
            if (!Auth::attempt($credentials)) {
                return response()->json([
                    'message' => 'Unauthorized'
                ], 401);
            }
            $user = $request->user();
            $tokenResult = $user->createToken('Personal Access Token');
            $token = $tokenResult->token;
            if ($request->remember_me)
                $token->expires_at = Carbon::now()->addWeeks(1);
            $token->save();
            return response()->json([
                'access_token' => $tokenResult->accessToken,
                'token_type' => 'Bearer',
                'expires_at' => Carbon::parse(
                    $tokenResult->token->expires_at
                )->toDateTimeString(),
                'user' => $user
            ]);
        }
        else {
            return response()->json([
                "message" => 'Not yet authentic'
            ], 500);
        }
    }
    public function logout(Request $request) {
        $request->user()->token()->revoke();
        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }
    public function user()
    {
        return response()->json(Auth::user());
    }
    public function verify($code, $email) {
        try {
            $user = User::where('email', '=', $email)
                ->where('verify_code', '=', $code)
                ->get()
                ->first();
            if ($user) {
                User::find($user['id'])
                    ->update(['verified' => 1, 'updated_at' => getCurrentTime()]);
                $user = User::find($user['id']);
                if ($user->verified == 1) {
                    DB::table('role_user')->insert([
                        [
                            'user_id' => $user->id,
                            'role_id' => 1
                        ],
                        [
                            'user_id' => $user->id,
                            'role_id' => 2
                        ]
                    ]);
                    $current_time = getCurrentTime();
                    $member_data = [
                        'username' => $user->username,
                        'user_id' => $user->id,
                        'created_at' => $current_time,
                        'updated_at' => $current_time
                    ];
                    Members::create($member_data);
                    return Redirect::to('https://quizlet-client.herokuapp.com/home');
                }
            }
            else {
                return response()->json([
                    'message' => 'Unknown User or PIN code wrong!',
                ], 401);
            }
        }
        catch (\Exception $exception) {
            return response()->json($exception->getMessage(), 500);
        }
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\AuthorizationRequest;
use App\Http\Requests\Api\SocialAuthorizationRequest;
use App\Http\Requests\Api\WeappAuthorizationRequest;
use App\Models\User;
use Auth;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Monolog\Handler\IFTTTHandler;

class AuthorizationsController extends Controller
{
    public function store(AuthorizationRequest $request)
    {
        $username = $request->username;
        filter_var($username, FILTER_VALIDATE_EMAIL) ?
            $credentials['email'] = $username :
            $credentials['phone'] = $username;

        $credentials['password'] = $request->password;

        if (!$token = Auth::guard('api')->attempt($credentials)) {
//            $this->response->errorUnauthorized('用户名或者密码错误');
            $this->response->errorUnauthorized(trans('auth.failed'));
        }

       return $this->responseWithToken($token)->setStatusCode(201);
    }


    // 第三方登录
    public function socialStore($type, SocialAuthorizationRequest $request)
    {
        if (!in_array($type, ['weixin'])) {
            $this->response->errorBadRequest();
        }

        $driver = Socialite::driver($type);

        try {
            if ($code = $request->code) {
                $response = $driver->getAccessTokenResponse($code);
                $token = Arr::get($response, 'access_token');
            } else {
                $token = $request->access_token;
                if ($type == 'weixin') {
                    $driver->setOpenId($request->openid);
                }
            }
            $oauthUser = $driver->userFromToken($token);
        } catch (\Exception $exception) {
            $this->response->errorUnauthorized('参数错误, 未获取用户信息');
        }

        switch ($type) {
            case 'weixin':
                $unionid = $oauthUser->offsetExists('unionid') ?? null;
                if ($unionid) {
                    $user = User::where('weixin_unionid', $unionid)->first();
                } else {
                    $user = User::where('weixin_openid', $oauthUser->getId())->first();
                }
                if (!$user) {
                    $user = User::create([
                        'name' => $oauthUser->getNickname(),
                        'avatar' => $oauthUser->getAvatar(),
                        'weixin_openid' => $oauthUser->getId(),
                        'weixin_unionid' => $unionid,
                    ]);
                }
                break;
        }

        $token = Auth::guard('api')->fromUser($user);
        return $this->responseWithToken($token)->setStatusCode(201);

    }

    // 微信小程序登录
    public function weappStore(WeappAuthorizationRequest $request)
    {
        $code = $request->code;

        // 根据code 获取微信的openid 和 session_key
        $miniProgram = \EasyWeChat::miniProgram();
        $data = $miniProgram->auth->session($code);

        if (isset($data['errcode'])) {
            return $this->response->errorUnauthorized('code 不正确');
        }

        // 找到openid 对应的用户
        $user = User::where('weapp_openid', $data['openid'])->first();
        $attributes['weixin_session_key'] = $data['session_key'];

        // 未找到对应的用户需要提交用户名和密码进行用户绑定
        if (!$user) {
            if (!$request->username) {
                return $this->response->errorForbidden('用户不存在');
            }

            $username = $request->username;
            // 用户名可以是邮箱或者电话
            filter_var($username, FILTER_VALIDATE_EMAIL) ?
                $credentials['email'] = $username :
                $credentials['phone'] = $username;
            $credentials['password'] = $request->password;

            // 验证用户名和密码是否正确
            if (!Auth::guard('api')->attempt($credentials)) {
                return $this->response->errorUnauthorized('用户名或者密码错误');
            }

            // 获取对应的用户
            $user = Auth::guard('api')->getUser();
            $attributes['weapp_openid'] = $data['openid'];
        }

        $user->update($attributes);

        // 为对应用户创建JWT
        $token = Auth::guard('api')->fromUser($user);
        return $this->responseWithToken($token)->setStatusCode(201);

    }


    // 重新获取token
    public function update()
    {
        $token = Auth::guard('api')->refresh();
        return $this->responseWithToken($token);
    }

    public function destroy()
    {
        Auth::guard('api')->logout();
        return $this->response->noContent();
    }


    protected function responseWithToken($token)
    {
        return $this->response->array([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => Auth::guard('api')->factory()->getTTL() * 60
        ]);
    }
}
<?php

/**
 * Social plugin for Craft
 *
 * @package   Craft Social
 * @author    Benjamin David
 * @copyright Copyright (c) 2015, Dukt
 * @link      https://dukt.net/craft/social/
 * @license   https://dukt.net/craft/social/docs/license
 */

namespace Craft;

class Social_AccountsService extends BaseApplicationComponent
{
    // Public Methods
    // =========================================================================

	public function getAccounts()
	{
		$conditions = '';
		$params = [];

		$records = Social_AccountRecord::model()->findAll($conditions, $params);

		if ($records)
		{
			return Social_AccountModel::populateModels($records);
		}
	}

	public function getAccountById($id)
	{
		$record = Social_AccountRecord::model()->findByPk($id);

		if ($record)
		{
			return Social_AccountModel::populateModel($record);
		}
	}

	// =========================================================================

	public function getAccountByGateway($gatewayHandle)
	{
		$currentUser = craft()->userSession->getUser();
		$userId = $currentUser->id;

		$conditions = 'gateway=:gateway and userId=:userId';
		$params = [':gateway' => $gatewayHandle, ':userId' => $userId];

		$record = Social_AccountRecord::model()->find($conditions, $params);

		if ($record)
		{
			return Social_AccountModel::populateModel($record);
		}
	}

	public function getAccountByUid($gatewayHandle, $socialUid)
	{
		$conditions = 'gateway=:gateway';
		$params = [':gateway' => $gatewayHandle];

		$conditions .= ' AND socialUid=:socialUid';
		$params[':socialUid'] = $socialUid;

		$record = Social_AccountRecord::model()->find($conditions, $params);

		if ($record)
		{
			return Social_AccountModel::populateModel($record);
		}
	}

	public function saveAccount(Social_AccountModel $account)
	{
		if ($account->id)
		{
			$accountRecord = Social_AccountRecord::model()->findById($account->id);

			if (!$accountRecord)
			{
				throw new Exception(Craft::t('No social user exists with the ID “{id}”', ['id' => $account->id]));
			}

			$oldSocialUser = Social_AccountModel::populateModel($accountRecord);
			$isNewUser = false;
		}
		else
		{
			$accountRecord = new Social_AccountRecord;
			$isNewUser = true;
		}

		// populate
		$accountRecord->userId = $account->userId;
		$accountRecord->tokenId = $account->tokenId;
		$accountRecord->gateway = $account->gateway;
		$accountRecord->socialUid = $account->socialUid;

		// validate
		$accountRecord->validate();

		$account->addErrors($accountRecord->getErrors());

		if (!$account->hasErrors())
		{
			$accountRecord->save(false);

			if (!$account->id)
			{
				$account->id = $accountRecord->id;
			}

			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Save Token
	 *
	 * @param object $tokenModel The token object we want to save
	 *
	 * @return null
	 */
	public function saveToken(Oauth_TokenModel $token)
	{
		$existingToken = null;

		if ($token->id)
		{
			$existingToken = craft()->oauth->getTokenById($token->id);

			if (!$existingToken)
			{
				$existingToken = null;
				$token->id = null;
			}
		}

		if ($token->providerHandle == 'google')
		{
			if (empty($token->refreshToken))
			{
				if ($existingToken)
				{
					if (!empty($existingToken->refreshToken))
					{
						// existing token has a refresh token so we keep it
						$token->refreshToken = $existingToken->refreshToken;
					}
				}


				// still no refresh token ? re-prompt

				if (empty($token->refreshToken))
				{
					$requestUri = craft()->httpSession->get('social.requestUri');
					$this->redirect($requestUri.'&forcePrompt=true');
				}
			}
		}

		// save token
		craft()->oauth->saveToken($token);
	}

	public function deleteAccountByProvider($gatewayHandle)
	{
		$currentUser = craft()->userSession->getUser();
		$userId = $currentUser->id;

		$conditions = 'gateway=:gateway and userId=:userId';
		$params = [':gateway' => $gatewayHandle, ':userId' => $userId];

		$record = Social_AccountRecord::model()->find($conditions, $params);

		$tokenId = $record->tokenId;

		if ($tokenId)
		{
			$tokenRecord = Oauth_TokenRecord::model()->findByPk($tokenId);

			if ($tokenRecord)
			{
				$tokenRecord->delete();
			}
		}


		if ($record)
		{
			return $record->delete();
		}

		return false;
	}

	public function deleteAccountByUserId($userId)
    {
        $conditions = 'userId=:userId';
        $params = array(':userId' => $userId);

        $accountRecords = Social_AccountRecord::model()->findAll($conditions, $params);

        foreach($accountRecords as $accountRecord)
        {
            if($accountRecord->tokenId)
            {
                $tokenRecord = Oauth_TokenRecord::model()->findByPk($accountRecord->tokenId);

                if($tokenRecord)
                {
                    $tokenRecord->delete();
                }
            }

            $accountRecord->delete();
        }

        return true;
    }

	/**
	 * Register User
	 *
	 * @param array $attributes Attributes of the user we want to register
	 *
	 * @throws Exception
	 * @return null
	 */
	public function registerUser($attributes, $gatewayHandle)
	{
		$this->_fillAttributes($attributes, $gatewayHandle);

		$temporaryPassword = md5(time());

		$attributes['newPassword'] = $temporaryPassword;

		if (!empty($attributes['email']))
		{
			// find with email
			$user = craft()->users->getUserByUsernameOrEmail($attributes['email']);

			if (!$user)
			{
				$user = $this->_registerUser($attributes, $gatewayHandle);

				if ($user)
				{
					$socialUser = new Social_UserModel;
					$socialUser->userId = $user->id;
					$socialUser->hasEmail = true;
					$socialUser->hasPassword = false;
					$socialUser->temporaryPassword = $temporaryPassword;

					craft()->social_users->saveSocialUser($socialUser);
				}
			}
			else
			{
				if (craft()->config->get('allowEmailMatch', 'social') !== true)
				{
					throw new \Exception("An account already exists with this email: ".$attributes['email']);
				}
			}
		}
		else
		{
			// no email at this point ? create a fake one

			$attributes['email'] = strtolower($gatewayHandle).'.'.$attributes['uid'].'@example.com';

			$user = $this->_registerUser($attributes, $gatewayHandle);

			if ($user)
			{
				$socialUser = new Social_UserModel;
				$socialUser->userId = $user->id;
				$socialUser->hasEmail = false;
				$socialUser->hasPassword = false;
				$socialUser->temporaryEmail = $user->email;
				$socialUser->temporaryPassword = $temporaryPassword;

				craft()->social_users->saveSocialUser($socialUser);
			}
		}

		return $user;
	}

	public function saveRemotePhoto($photoUrl, $user)
	{
		$filename = 'photo';

		$tempPath = craft()->path->getTempPath().'social/userphotos/'.$user->email.'/';
		IOHelper::createFolder($tempPath);
		$tempFilepath = $tempPath.$filename;
		$client = new \Guzzle\Http\Client();
		$response = $client->get($photoUrl)
			->setResponseBody($tempPath.$filename)
			->send();


		$extension = substr($response->getContentType(), strpos($response->getContentType(), "/") + 1);

		IOHelper::rename($tempPath.$filename, $tempPath.$filename.'.'.$extension);

		craft()->users->deleteUserPhoto($user);

		$image = craft()->images->loadImage($tempPath.$filename.'.'.$extension);
		$imageWidth = $image->getWidth();
		$imageHeight = $image->getHeight();

		$dimension = min($imageWidth, $imageHeight);
		$horizontalMargin = ($imageWidth - $dimension) / 2;
		$verticalMargin = ($imageHeight - $dimension) / 2;
		$image->crop($horizontalMargin, $imageWidth - $horizontalMargin, $verticalMargin, $imageHeight - $verticalMargin);

		craft()->users->saveUserPhoto($filename.'.'.$extension, $image, $user);

		IOHelper::deleteFile($tempPath.$filename.'.'.$extension);

		return true;
	}

	public function onBeforeRegister(Event $event)
	{
		$this->raiseEvent('onBeforeRegister', $event);
	}


    // Private Methods
    // =========================================================================

	private function _registerUser($account, $gatewayHandle)
	{
		// get social plugin settings

		$socialPlugin = craft()->plugins->getPlugin('social');
		$settings = $socialPlugin->getSettings();

		if (!$settings['allowSocialRegistration'])
		{
			throw new Exception("Social registration is disabled.");
		}


		// new user

		if (isset($account['email']))
		{
			// define email
			$usernameOrEmail = $account['email'];
		}
		else
		{
			throw new Exception("Email address not provided.");
		}


		// Fire an 'onBeforeRegister' event

		$event = new Event($this, [
			'account' => $account,
		]);

		$this->onBeforeRegister($event);

		if ($event->performAction)
		{
			$newUser = new UserModel();
			$newUser->username = $usernameOrEmail;
			$newUser->email = $usernameOrEmail;

			if (!empty($account['firstName']))
			{
				$newUser->firstName = $account['firstName'];
			}

			if (!empty($account['lastName']))
			{
				$newUser->lastName = $account['lastName'];
			}

			$newUser->newPassword = $account['newPassword'];


			// save user

			craft()->users->saveUser($newUser);
			craft()->db->getSchema()->refresh();
			$user = craft()->users->getUserByUsernameOrEmail($usernameOrEmail);

			// save photo

			if (!empty($account['photo']))
			{
				craft()->social_accounts->saveRemotePhoto($account['photo'], $user);
			}


			// save profile attributes

			$profileFieldsMapping = craft()->config->get('profileFieldsMapping', 'social');

			if(isset($profileFieldsMapping[$gatewayHandle]))
			{
				$variables = $account;

				foreach($profileFieldsMapping[$gatewayHandle] as $field => $template)
				{
					$user->getContent()->{$field} = craft()->templates->renderString($template, $variables);
				}
			}

			// save groups

			if (!empty($settings['defaultGroup']))
			{
				craft()->userGroups->assignUserToGroups($user->id, [$settings['defaultGroup']]);
			}

			craft()->users->saveUser($user);

			return $user;
		}

		return false;
	}

	/**
	 * Fill Attributes From Profile
	 *
	 * @param array $attributes Attributes we want to fill the profile with
	 * @param array $profile    The profile we want to fill attributes with
	 *
	 * @throws Exception
	 * @return null
	 */
	private function _fillAttributes(&$attributes, $gatewayHandle)
	{
		$socialProvider = craft()->social_gateways->getGateway($gatewayHandle);
		$socialProvider->setToken($this->token);
		$profile = $socialProvider->getProfile();

		$plugin = craft()->plugins->getPlugin('social');
		$settings = $plugin->getSettings();

		if ($settings->autoFillProfile)
		{
			if (!empty($profile['firstName']))
			{
				$attributes['firstName'] = $profile['firstName'];
			}

			if (!empty($profile['lastName']))
			{
				$attributes['lastName'] = $profile['lastName'];
			}

			if (!empty($profile['photo']))
			{
				$attributes['photo'] = $profile['photo'];
			}
		}
	}
}

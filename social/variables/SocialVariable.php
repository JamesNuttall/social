<?php

namespace Craft;

class SocialVariable
{
    // --------------------------------------------------------------------

    public function login($providerClass, $redirect = null, $scope = null)
    {
        return craft()->social->login($providerClass, $redirect, $scope);
    }

    // --------------------------------------------------------------------

    public function logout($redirect = null)
    {
        return craft()->social->logout($redirect);
    }


    // --------------------------------------------------------------------

    public function userHasTemporaryEmail($userId)
    {
        return craft()->social->userHasTemporaryEmail($userId);
    }

    // --------------------------------------------------------------------

    public function userHasTemporaryUsername($userId)
    {
        return craft()->social->userHasTemporaryUsername($userId);
    }

    // --------------------------------------------------------------------
}

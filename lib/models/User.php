<?php

class User
{
    public static $oDB = null;

    public static function authorize($sDatabaseName, $sAuthorizationCookie)
    {
        return self::$oDB->selectRecord("
            SELECT *
                FROM postgresql_deployer.authorize_user(?w);
        ",
            $sAuthorizationCookie
        );
    }

    public static function login($sEmail, $sPassword)
    {
        return self::$oDB->selectField("
            SELECT cookie
                FROM postgresql_deployer.login_user(?w, ?w);
        ",
            $sEmail, $sPassword
        );
    }

    public static function getCurrentUserId($sDatabaseName, $sAuthorizationCookie)
    {
        $aUser = self::authorize($sDatabaseName);
        return $aUser['id'];
    }

}



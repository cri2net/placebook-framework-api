<?php

namespace Placebook\Framework\Core;

use Exception;
use cri2net\php_pdo_db\PDO_DB;

/**
 * The class is designed to work with API access tokens:
 * checking permissions, imposing restrictions on API responses, managing tokens and their access
 */
class ApiTokens
{
    /**
     * Getting Authorization Token from HTTP by Request Header
     * @return string|false Token
     */
    public static function getTokenFromHeaders()
    {
        $http_headers = Http::getAllHeaders();

        if (isset($http_headers['Authorization'])) {
            return trim($http_headers['Authorization']);
        }

        return false;
    }

    /**
     * Adding a new token
     * @param  string  $title Token name. Can be used as description
     * @param  string  $token A random string with a token, such as the result of Codes::generate(64). The token must be unique
     * @return integer ID of the added token
     */
    public static function add(string $title, string $token) : int
    {
        if (self::getTokenByString($token) !== null) {
            throw new Exception(self::getError('TOKEN_ALREADY_EXISTS'));
        }

        $arr = [
            'created_at' => microtime(true),
            'token'      => $token,
            'title'      => $title,
            'site_id'    => 0,
            'is_active'  => 1,
            'is_root'    => 0,
        ];
        $prefix = (defined('TABLE_PREFIX')) ? TABLE_PREFIX : '';
        $insert_id = PDO_DB::insert($arr, $prefix . 'api_tokens');

        return $insert_id;
    }

    /**
     * Getting a record from the database about a token
     * @param  string      $token Token string
     * @return array|null  Token data
     */
    public static function getTokenByString(string $token) : ?array
    {
        $prefix = (defined('TABLE_PREFIX')) ? TABLE_PREFIX : '';
        return PDO_DB::row_by_id($prefix . 'api_tokens', $token, 'token');
    }

    /**
     * Access Token verification.
     * It is checked whether such a Token is present at all, and not its specific rights
     *
     * @throws Exception if Authorization HTTP header not presented
     * @throws Exception if Token not found
     * @throws Exception if Token disabled
     * @return array Token data
     */
    public static function checkToken() : array
    {
        $token = self::getTokenFromHeaders();
        if ($token === false) {
            throw new Exception(self::getError('HTTP_HEADER_IS_EMPTY'), 401);
        }

        $row = self::getTokenByString($token);

        if ($row === null) {
            throw new Exception(self::getError('NOT_FOUND'));
        }

        if (!$row['is_active']) {
            throw new Exception(self::getError('NOT_ACTIVE'));
        }

        return $row;
    }

    /**
     * Checking a token for the right to access a specific «field» of the API
     * 
     * @param  string $name  Case sensitive! API field name
     * @param  string $type  Field type: query|mutation
     * @param  string $token Token whose rights we are checking. OPTIONAL
     *
     * @throws Exception     if Token not found
     * @throws Exception     if Token disabled
     * @throws Exception     if token does not have access to field
     * 
     * @return array         Token access data to the API field
     */
    public static function checkPermis(string $name, string $type, string $token = null) : array
    {
        if ($token === null) {
            $token = self::getTokenFromHeaders();
        }
        
        $row = self::getTokenByString($token);

        if ($row === null) {
            throw new Exception(self::getError('NOT_FOUND'));
        }
        if (!$row['is_active']) {
            throw new Exception(self::getError('NOT_ACTIVE'));
        }

        // for root access, we do not check against the database, but return the maximum access to the field
        if ($row['is_root']) {
            return [
                'token_id'  => $row['id'],
                'query_key' => "$type:$name",
                'is_full'   => true,
                'fields'    => [],
            ];
        }

        $prefix = (defined('TABLE_PREFIX')) ? TABLE_PREFIX : '';
        $stm = PDO_DB::prepare("SELECT * FROM " . $prefix . "api_token_permission WHERE token_id = ? AND query_key = ?");
        $stm->execute([$row['id'], "$type:$name"]);

        $permiss = $stm->fetch();

        if ($permiss === false) {
            $err = str_replace('{{FIELD}}', "$type:$name", self::getError('NO_ACCESS'));
            throw new Exception($err);
        }

        $permiss['fields'] = @json_decode($permiss['fields']);
        if ($permiss['fields'] === null) {
            $permiss['fields'] = [];
        }

        return $permiss;
    }

    /**
     * The method removes all fields that have no access
     * Recursion is used to process nested arrays
     * 
     * @param  array  $data  Array with data. Multilevel Arrays Allowed
     * @param  array  $rules An array with allowed keys. Multilevel Arrays Allowed
     * @return array  The original array with data, without keys, which are indicated in the list of allowed
     */
    public static function unsetFieldsWithoutPermission(array $data, array $rules) : array
    {
        foreach ($data as $key => $value) {
            if (!in_array($key, $rules) && !array_key_exists($key, $rules)) {
                unset($data[$key]);
            } elseif (is_array($rules[$key]) && is_array($data[$key])) {
                $data[$key] = self::unsetFieldsWithoutPermission($data[$key], $rules[$key]);
            }
        }

        return $data;
    }

    /**
     * Get error description by code
     * @param  string $code Error code
     * @param  string $lang Desired error language. OPTIONAL
     * @return string Error description
     */
    public static function getError(string $code, string $lang = null) : string
    {
        if ($lang == null) {
            $lang = SystemConfig::get('lang', 'ru');
        }

        $errors = [
            'ru' => [
                'NO_ACCESS'            => 'Нет доступа к полю «{{FIELD}}»',
                'NOT_FOUND'            => 'Токен не найден',
                'NOT_ACTIVE'           => 'Токен доступа приостановлен',
                'HTTP_HEADER_IS_EMPTY' => 'HTTP заголовок Authorization не передан',
                'TOKEN_ALREADY_EXISTS' => 'Токен уже существует',
            ],
            'ua' => [
                'NO_ACCESS'            => 'немає доступу до поля «{{FIELD}}»',
                'NOT_FOUND'            => 'Токен не знайдено',
                'NOT_ACTIVE'           => 'Токен доступу призупинено',
                'HTTP_HEADER_IS_EMPTY' => 'HTTP заголовок Authorization не переданий',
                'TOKEN_ALREADY_EXISTS' => 'Токен вже існує',
            ],
            'en' => [
                'NO_ACCESS'            => 'Have\'nt access to «{{FIELD}}»',
                'NOT_FOUND'            => 'Token not found',
                'NOT_ACTIVE'           => 'Access token is suspended',
                'HTTP_HEADER_IS_EMPTY' => 'Authorization HTTP header not presented',
                'TOKEN_ALREADY_EXISTS' => 'Token already exists',
            ],
        ];

        if (!isset($errors[$lang])) {
            $lang = 'ru';
        }

        return $errors[$lang][$code] ?? $code;
    }
}

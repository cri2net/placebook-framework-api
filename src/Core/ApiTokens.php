<?php

namespace Placebook\Framework\Core;

use \Exception;
use \cri2net\php_pdo_db\PDO_DB;

/**
 * Класс предназначен для работы с токенами доступа к API:
 * проверка прав, наложение ограничений на ответы API, управление токенами и их доступом
 */
class ApiTokens
{
    /**
     * Получение Токена авторизации из HTTP Заголовкой запроса
     * @return string|false Токен
     */
    public static function getTokenFromHeaders()
    {
        if (function_exists('getallheaders')) {
            $http_headers = getallheaders();
        } else {
            $http_headers = Http::getAllHeaders();
        }

        if (isset($http_headers['Authorization'])) {
            return trim($http_headers['Authorization']);
        }

        return false;
    }

    /**
     * Добавление нового токена
     * @param  string  $title Название токена. Можно использовать как описание
     * @param  string  $token Случайная строка с токенов, например, результат Codes::generate(64). Токен должен быть уникальным
     * @return integer ID добавленного токена
     */
    public static function add($title, $token)
    {
        if (self::getTokenByString($token) !== null) {
            throw new Exception(self::getError('TOKEN_ALREADY_EXISTS'));
        }

        $arr = [
            'created_at' => microtime(true),
            'token'      => $token,
            'title'      => $title,
        ];
        $prefix = (defined('TABLE_PREFIX')) ? TABLE_PREFIX : '';
        $insert_id = PDO_DB::insert($arr, $prefix . 'api_tokens');

        return $insert_id;
    }

    /**
     * Получение записи из БД о токене
     * @param  string      $token Строка с токеном
     * @return array|null  Данные о токене
     */
    public static function getTokenByString($token)
    {
        $prefix = (defined('TABLE_PREFIX')) ? TABLE_PREFIX : '';
        $stm = PDO_DB::prepare("SELECT * FROM " . $prefix . "api_tokens WHERE token = ? LIMIT 1", [$token]);
        $row = $stm->fetch();

        if ($row === false) {
            return null;
        }

        return $row;
    }

    /**
     * Проверка Токена доступа.
     * Проверяется, присутствует ли такое Токен вообще, а не его конкретные права
     *
     * @throws Exception if Authorization HTTP header not presented
     * @throws Exception if Token not found
     * @throws Exception if Token disabled
     * @return array Данные о токене
     */
    public static function checkToken()
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
     * Проверка токена на право обращаться к определённому «полю» API
     * 
     * @param  string $name  Регистрозависимое! название поля API
     * @param  strign $type  Тип поля: query|mutation
     * @param  string $token Токен, права которого проверяем. OPTIONAL
     *
     * @throws Exception     if token not found
     * @throws Exception     if Token not found
     * @throws Exception     if Token disabled
     * @throws Exception     if token does not have access to field
     * 
     * @return array         Данные о доступе Токена к полю API
     */
    public static function checkPermis($name, $type, $token = null)
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

        // для root доступа не делаем проверку по БД, а возвращаем максимальный доступ к полю
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
     * Метод удаляет все поля, на которых нет доступа
     * Используется рекурсия для обработки вложенных массивов
     * 
     * @param  array  $data  Массив с данными. Допускаются многоуровневые массивы
     * @param  array  $rules Массив с разрешёнными ключами. Допускаются многоуровневые массивы
     * @return array  Исходный массив с данными, без ключей, которые указаны в списке разрешённых
     */
    public static function unsetFiledsWithoutPermission($data, $rules)
    {
        if (!is_array($data)) {
            return $data;
        }

        $rules = (array)$rules;

        foreach ($data as $key => $value) {
            if (!in_array($key, $rules) && !array_key_exists($key, $rules)) {
                unset($data[$key]);
            } elseif (is_array($rules[$key]) && is_array($data[$key])) {
                $data[$key] = self::unsetFiledsWithoutPermission($data[$key], $rules[$key]);
            }
        }

        return $data;
    }

    /**
     * Получаем описание ошибки по коду
     * @param  string $code Код ошибки
     * @param  string $lang Желаемый язык описания ошибки. OPTIONAL
     * @return string Описание кода
     */
    public static function getError($code, $lang = null)
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

        return (isset($errors[$lang][$code])) ? $errors[$lang][$code] : null;
    }
}

<?php

namespace Placebook\Framework\Modules\Api\Install;

use \Exception;
use cri2net\php_pdo_db\PDO_DB;
use \Placebook\Framework\Core\Install\MigrationInterface;

class Migration_1 implements MigrationInterface
{
    public static function up()
    {
        $prefix = (defined('TABLE_PREFIX')) ? TABLE_PREFIX : '';
        $pdo = PDO_DB::getPDO();

        try {
            $pdo->beginTransaction();

            switch (PDO_DB::getParams('type')) {
                case 'pgsql':
                    PDO_DB::query("CREATE SEQUENCE {$prefix}api_tokens_seq;");
                    
                    PDO_DB::query(
                        "CREATE TABLE IF NOT EXISTS {$prefix}api_tokens (
                            id int NOT NULL DEFAULT NEXTVAL ('{$prefix}api_tokens_seq'),
                            site_id int NOT NULL DEFAULT '0',
                            created_at double precision NOT NULL,
                            last_used double precision DEFAULT NULL,
                            is_active smallint NOT NULL DEFAULT '1',
                            is_root smallint NOT NULL DEFAULT '0',
                            title varchar(500) NOT NULL,
                            token varchar(1000) NOT NULL,
                            PRIMARY KEY (id)
                        );"
                    );

                    PDO_DB::query("ALTER SEQUENCE {$prefix}api_tokens_seq RESTART WITH 1;");
                    PDO_DB::query("CREATE INDEX is_active ON {$prefix}api_tokens (is_active);");
                    PDO_DB::query("CREATE SEQUENCE {$prefix}api_token_permission_seq;");

                    PDO_DB::query(
                        "CREATE TABLE IF NOT EXISTS {$prefix}api_token_permission (
                            id int NOT NULL DEFAULT NEXTVAL ('{$prefix}api_token_permission_seq'),
                            token_id int NOT NULL,
                            query_key varchar(500) NOT NULL,
                            is_full smallint NOT NULL DEFAULT '1',
                            fields varchar(3000) NOT NULL,
                            PRIMARY KEY (id)
                        );"
                    );

                    PDO_DB::query("ALTER SEQUENCE {$prefix}api_token_permission_seq RESTART WITH 1;");
                    PDO_DB::query("CREATE INDEX token_id ON {$prefix}api_token_permission (token_id);");
                    PDO_DB::query(
                        "ALTER TABLE {$prefix}api_token_permission
                            ADD CONSTRAINT {$prefix}api_token_permission_ibfk_1 FOREIGN KEY (token_id) REFERENCES {$prefix}api_tokens (id) ON DELETE CASCADE ON UPDATE NO ACTION;"
                    );
                    break;
                
                case 'mysql':
                default:
                    PDO_DB::query("SET FOREIGN_KEY_CHECKS=0;");
                    
                    PDO_DB::query(
                        "CREATE TABLE IF NOT EXISTS {$prefix}api_tokens (
                          id int(11) NOT NULL AUTO_INCREMENT,
                          site_id int(11) NOT NULL DEFAULT '0',
                          created_at double NOT NULL,
                          last_used double DEFAULT NULL,
                          is_active tinyint(1) NOT NULL DEFAULT '1',
                          is_root tinyint(1) NOT NULL DEFAULT '0',
                          title varchar(500) NOT NULL,
                          token varchar(1000) NOT NULL,
                          PRIMARY KEY (`id`),
                          KEY is_active (is_active)
                        ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;"
                    );
                    PDO_DB::query(
                        "CREATE TABLE IF NOT EXISTS {$prefix}api_token_permission (
                          id int(11) NOT NULL AUTO_INCREMENT,
                          token_id int(11) NOT NULL,
                          query_key varchar(500) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
                          is_full tinyint(1) NOT NULL DEFAULT '1',
                          fields varchar(3000) NOT NULL,
                          PRIMARY KEY (id),
                          KEY token_id (token_id)
                        ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;"
                    );
                    PDO_DB::query(
                        "ALTER TABLE {$prefix}api_token_permission
                            ADD CONSTRAINT {$prefix}api_token_permission_ibfk_1 FOREIGN KEY (token_id) REFERENCES {$prefix}api_tokens (id) ON DELETE CASCADE ON UPDATE NO ACTION;"
                    );

                    PDO_DB::query("SET FOREIGN_KEY_CHECKS=1;");
            }

            $pdo->commit();

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function down()
    {
        $prefix = (defined('TABLE_PREFIX')) ? TABLE_PREFIX : '';
        $pdo = PDO_DB::getPDO();

        try {
            $pdo->beginTransaction();

            switch (PDO_DB::getParams('type')) {
                case 'pgsql':
                    PDO_DB::query("ALTER TABLE tpl_api_token_permission DROP CONSTRAINT {$prefix}api_token_permission_ibfk_1;");
                    PDO_DB::query("DROP TABLE IF EXISTS {$prefix}api_token_permission");
                    PDO_DB::query("DROP TABLE IF EXISTS {$prefix}api_tokens");

                    PDO_DB::query("DROP SEQUENCE IF EXISTS {$prefix}api_tokens_seq;");
                    PDO_DB::query("DROP SEQUENCE IF EXISTS {$prefix}api_token_permission_seq;");
                    break;

                case 'mysql':
                default:
                    PDO_DB::query("SET FOREIGN_KEY_CHECKS=0;");
                    PDO_DB::query("DROP TABLE IF EXISTS {$prefix}api_tokens");
                    PDO_DB::query("DROP TABLE IF EXISTS {$prefix}api_token_permission");
                    PDO_DB::query("SET FOREIGN_KEY_CHECKS=1;");
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

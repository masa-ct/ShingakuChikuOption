<?php
require_once __DIR__ . '/../bootstrap.php';
require_once BASE_DIR . 'CustomConst.php';
require_once UTIL_DIR . 'requireIdPassWord.php';

use Chikuoption\base\CustomConst;

class checkClientsUseChikuOption
{
    /** @var  PDO */
    private $_admin;
    /** @var  PDO */
    private $_clientdb;
    /** @var  array */
    private $_databases;
    /** @var  array */
    private $_clients;

    /**
     * checkClientsUseChikuOption constructor.
     */
    public function __construct()
    {
        $this->setAdmin();

        $this->getDatabases();
        $this->getClients();

        $this->deleteClientsHasNoDatabases();

        $this->checkOptionSettings();
        print_r($this->_clients);
    }

    /**
     * adminデータベースのセット
     * @internal param PDO $admin
     */
    public function setAdmin()
    {
        $password = requireIdPassWord::getParam(CustomConst::C_SERVER, 'パスワード');
        $dsn = sprintf('mysql:host=%s;dbname=%suadmin', CustomConst::C_SERVER, CustomConst::C_NEN);
        $this->_admin = new PDO($dsn, CustomConst::C_USER, $password);
        $this->_admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->_admin->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $dsn = sprintf('mysql:host=%s', CustomConst::C_SERVER);
        $this->_clientdb = new PDO($dsn, CustomConst::C_USER, $password);
        $this->_clientdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->_clientdb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    }

    /**
     * クライアントの取得
     * @return array
     */
    private function getClients()
    {
        /** @var PDOStatement $sth */
        $sth = $this->_admin->prepare('SELECT CONCAT(\'u' . CustomConst::C_NEN . '\',clc) AS clc,clname,nickname FROM client WHERE is_demo=?');
        $sth->execute([0]);
        while ($str = $sth->fetch(PDO::FETCH_ASSOC)) {
            $this->_clients[$str['clc']] = ['clname' => $str['clname'], 'nickname' => $str['nickname']];
        }
    }

    /**
     * データベース一覧の取得
     * @return array
     */
    private function getDatabases()
    {
        /** @var PDOStatement $sth */
        $sth = $this->_admin->prepare('SHOW DATABASES LIKE \'' . 'u' . CustomConst::C_NEN . '%\'');
        $sth->execute();
        $this->_databases = array_column($sth->fetchAll(PDO::FETCH_NUM), 0);
    }

    /**
     * データベースの存在しないクライアントを削除する
     */
    private function deleteClientsHasNoDatabases()
    {
        foreach ($this->_clients as $dbname => $client) {
            if (!in_array($client, $this->_clients)) {
                unset($this->_clients[$dbname]);
            }
        }
    }

    /**
     * @param boolean $omit // 地区オプションの設定のないものを除外する
     */
    private function checkOptionSettings($omit = true)
    {
        /** @var PDOStatement $sth_set_kais */
        $sth_set_kais = $this->_clientdb->prepare('SELECT `value` FROM set_kais WHERE item=\'is_chiku\'');
        /** @var PDOStatement $sth_chiku_mast */
        $sth_chiku_mast = $this->_clientdb->prepare('SELECT chikucd FROM chiku');
        /** @var PDOStatement $sth_chiku */
        $sth_chiku = $this->_clientdb->prepare('SELECT yubincd FROM chiku');
        foreach ($this->_clients as $dbname => $client) {
            $this->_clientdb->query('USE ' . $dbname);
            $settings = [];
            // 各データベースを回って、set_kaisでの設定、chiku_mastの設定、chikuの設定を調べる
            $settings['set_kais'] = $this->hasSetKais($sth_set_kais);
            $settings['chiku_mast'] = $this->hasChikuMast($sth_chiku_mast);
            $settings['chiku'] = $this->hasChiku($sth_chiku);
            if ($omit && !array_filter($settings)) {
                unset($this->_clients[$dbname]);
            } else {
                $this->_clients[$dbname]['settings'] = $settings;
            }
        }
    }

    /**
     * @param PDOStatement $sth_set_kais
     * @return bool
     */
    private function hasSetKais($sth_set_kais)
    {
        // レコードがあり、valueが1のときにtrueを返す
        $sth_set_kais->execute();
        if ($str = $sth_set_kais->fetch(PDO::FETCH_ASSOC)) {
            if ($str['value'] == 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param PDOStatement $sth_chiku_mast
     * @return bool
     */
    private function hasChikuMast($sth_chiku_mast)
    {
        // レコードがあった場合にtrueを返す
        $sth_chiku_mast->execute();
        if ($str = $sth_chiku_mast->fetch(PDO::FETCH_ASSOC)) {
            return true;
        }
        return false;
    }

    /**
     * @param PDOStatement $sth_chiku
     * @return bool
     */
    private function hasChiku($sth_chiku)
    {
        // レコードがあった場合にtrueを返す
        $sth_chiku->execute();
        if ($str = $sth_chiku->fetch(PDO::FETCH_ASSOC)) {
            return true;
        }
        return false;
    }
}

$check_clients_use_chiku_option = new checkClientsUseChikuOption();
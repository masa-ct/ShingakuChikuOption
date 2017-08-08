<?php
require_once __DIR__ . '/../util/requireIdPassWord.php';
require_once __DIR__ . '/../util/getZipData.php';
require_once __DIR__ . '/../util/createExcelData.php';
date_default_timezone_set('Asia/Tokyo');

// 引数の処理
$opts = getopt('hn:');
if (!$opts
    || !array_key_exists('n', $opts)
    || array_key_exists('h', $opts)
) {
    print("*===============================================================================\n");
    print("* 地区オプション更新　オプション一覧\n*\n");
    print("*   -n : (nickname) 必須。\n");
    print("*                      対象学校のnicknameを指定。\n");
    print("*===============================================================================\n");
    exit;
}


/**
 * 現在設定されている地区設定を反映した郵便番号データを作成します
 * Class createZipDataIncludeCurrentSettings
 */
class createZipDataIncludeCurrentSettings
{
    const C_SERVER = 'ono';
    const C_ADMINBASE = 'uadmin';
    const C_USER = 'tap';
    const C_NEN = 18;

    private $_nickname;
    /** @var  PDO */
    private $_admin;
    private $_clname;
    /** @var  PDO */
    private $_clientdb;
    private $_prefectures;
    private $_chiku_settings;
    private $_clc;

    public function __construct($nickname)
    {
        $this->setNickname($nickname);
        $this->setAdminClient();
        $this->getChikuSettings();      // 地区の設定を取得し、その情報から地区を設定している都道府県を判定する
    }

    /**
     * @param string $nickname
     */
    public function setNickname($nickname)
    {
        $this->_nickname = $nickname;
    }

    /**
     * サーバーのセット
     */
    public function setAdminClient()
    {
        $password = requireIdPassWord::getParam(self::C_SERVER, 'パスワード');
        $dsn = sprintf('mysql:host=%s;dbname=%s%s', self::C_SERVER, self::C_NEN, self::C_ADMINBASE);

        try {
            $this->_admin = new PDO($dsn, self::C_USER, $password);

            // nicknameからclientを検索
            $sth = $this->_admin->prepare('SELECT clc,clname FROM client WHERE nickname=?');
            $sth->execute([$this->_nickname]);
            if (!$str = $sth->fetch(PDO::FETCH_ASSOC)) {
                printf("入力されたnickname「%s」に該当する学校はありませんでした。\n", $this->_nickname);
                exit;
            }
            $this->_clc = $str['clc'];
            $this->_clname = $str['clname'];
            // clientデータベースのセット
            $dsn = sprintf('mysql:host=%s;dbname=u%s%s', self::C_SERVER, self::C_NEN, $str['clc']);
            $this->_clientdb = new PDO($dsn, self::C_USER, $password);
        } catch (Exception $e) {
            echo $e->getMessage() . PHP_EOL;
            exit;
        }
    }

    private function getChikuSettings()
    {
        $sth = $this->_clientdb->prepare('SELECT yubincd,chikuname,chiku.chikucd FROM chiku LEFT JOIN chiku_mast USING (chikucd)');
        $sth->execute();
        $str = $sth->fetchAll(PDO::FETCH_ASSOC);
        $sth->closeCursor();
        // 地区の設定レコードが0件の場合は処理を終了する
        if (count($str) == 0) {
            echo "指定された学校には地区の設定がないので、処理を中止します。\n";
            exit;
        }
        // 郵便番号をキーとした配列として格納する
        $this->_chiku_settings = [];
        foreach ($str as $item) {
            $this->_chiku_settings[$item['yubincd']] = ['chikucd' => $item['chikucd'], 'chikuname' => $item['chikuname']];
        }
        // 設定されている郵便番号の取得
        $zip_codes = array_keys($this->_chiku_settings);

        // 設定されている都道府県の取得
        $sql = 'SELECT code_ken FROM yubin WHERE yubincd IN (' . substr(str_repeat(',?', count($zip_codes)), 1) . ') GROUP BY code_ken';
        $sth = $this->_admin->prepare($sql);
        $sth->execute($zip_codes);
        $this->_prefectures = array_column($sth->fetchAll(PDO::FETCH_ASSOC), 'code_ken');
    }

    public function run()
    {
        if (!getZipData::downloadData($this->_prefectures)) {
            echo 'データダウンロードに失敗しました' . PHP_EOL;
            exit;
        }

        $create_excel_data = new createExcelData($this->_admin, $this->_clc, $this->_clname, $this->_chiku_settings, $this->_prefectures);
        $create_excel_data->createExcelFiles();

        getZipData::cleanUpZipData();
    }
}

$create_zip_data_include_current_settings = new createZipDataIncludeCurrentSettings($opts['n']);
$create_zip_data_include_current_settings->run();
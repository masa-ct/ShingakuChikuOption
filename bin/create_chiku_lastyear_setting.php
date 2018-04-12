<?php
ini_set('display_errors', 1);
set_include_path(__DIR__ . '/../util/Classes');
include_once('PHPExcel.php');
require_once __DIR__ . '/../util/requireIdPassWord.php';
require_once __DIR__ . '/../util/createExcelData.php';
date_default_timezone_set('Asia/Tokyo');
error_reporting(E_ALL);

/**
 * 今年度の地区オプションの設定を最新の郵便番号データに適用し、追加用データを作成します
 * Class createLastYearSettings
 */
class createLastYearSettings
{
    const C_SERVER = 'tokushima';
    const C_PORT = 3306;
    const C_ADMINBASE = 'uadmin';
    const C_USER = 'selector';
    const C_YEAR = '19';    // 参照する年度

    private $files;
    private $db;
    private $exists_last_year;
    private $exists_this_year;
    private $databases;

    private $log_file_name;     // ログファイル

    /**
     * createLastYearSettings constructor.
     */
    public function __construct()
    {
        $this->files = array(
            1 => '01hokkai.zip',
            2 => '02aomori.zip',
            3 => '03iwate.zip',
            4 => '04miyagi.zip',
            5 => '05akita.zip',
            6 => '06yamaga.zip',
            7 => '07fukush.zip',
            8 => '08ibarak.zip',
            9 => '09tochig.zip',
            10 => '10gumma.zip',
            11 => '11saitam.zip',
            12 => '12chiba.zip',
            13 => '13tokyo.zip',
            14 => '14kanaga.zip',
            15 => '15niigat.zip',
            16 => '16toyama.zip',
            17 => '17ishika.zip',
            18 => '18fukui.zip',
            19 => '19yamana.zip',
            20 => '20nagano.zip',
            21 => '21gifu.zip',
            22 => '22shizuo.zip',
            23 => '23aichi.zip',
            24 => '24mie.zip',
            25 => '25shiga.zip',
            26 => '26kyouto.zip',
            27 => '27osaka.zip',
            28 => '28hyogo.zip',
            29 => '29nara.zip',
            30 => '30wakaya.zip',
            31 => '31tottor.zip',
            32 => '32shiman.zip',
            33 => '33okayam.zip',
            34 => '34hirosh.zip',
            35 => '35yamagu.zip',
            36 => '36tokush.zip',
            37 => '37kagawa.zip',
            38 => '38ehime.zip',
            39 => '39kochi.zip',
            40 => '40fukuok.zip',
            41 => '41saga.zip',
            42 => '42nagasa.zip',
            43 => '43kumamo.zip',
            44 => '44oita.zip',
            45 => '45miyaza.zip',
            46 => '46kagosh.zip',
            47 => '47okinaw.zip'
        );

        try {
            // パスワードの入力を求め、サーバーに接続する
            $pass = requireIdPassWord::getParam(self::C_SERVER, 'パスワード');
            $dsn = sprintf("mysql:host=%s;port=%s;dbname=%suadmin", self::C_SERVER, self::C_PORT, self::C_YEAR);
            /** @var PDO $db */
            $this->db = new PDO($dsn, self::C_USER, $pass);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            echo '捕捉した例外: ' . $e->getMessage() . PHP_EOL;
            exit;
        }

        if (false === $this->getZipData()) {
            echo '郵便番号データの取得に失敗したので、作業を中止します。' . PHP_EOL;
            exit;
        }

        $this->exists_last_year = array();
        $this->exists_this_year = array();

        // ログファイルの設定
        $this->initLogFile();

        $this->getDatabases();

        $this->getUsedDatabases();

        $this->createExcelFiles();

        $this->cleanUpZipData();
    }

    private function getDatabases()
    {
        // 存在するデータベース一覧(新年度と昨年度)
        $sth = $this->db->query(sprintf("SHOW DATABASES LIKE 'u%s______'", self::C_YEAR));
        while ($str = $sth->fetch(PDO::FETCH_NUM)) {
            $this->exists_this_year[] = $str[0];
        }
        $sth->closeCursor();

        $this->exists_this_year = array_filter($this->exists_this_year, 'not_is_demo');
    }

    private function getUsedDatabases()
    {
        // 参照する年度のデータベースでクライアントテーブルでオープンになっているものを抽出
        $sth = $this->db->query(sprintf("SELECT `clc`,`nickname`,`clname` FROM `%suadmin`.`client` WHERE `open` = 1", self::C_YEAR));
        $this->databases = [];
        while ($str = $sth->fetch(PDO::FETCH_NUM)) {
            if (in_array(sprintf('u%s%s', self::C_YEAR, $str[0]), $this->exists_this_year)) {
                $this->databases[] = array('clc' => $str[0], 'nickname' => $str[1], 'clname' => $str[2]);
            }
        }

        // 上で抽出したデータベースについて、地区オプションを使用しているものに絞り込む
        $sth = $this->db->prepare("select value from set_kais where class='ks' and item='is_chiku'");
        foreach ($this->databases as $key => $database) {
            $is_chiku = false;
            $this->db->query(sprintf('use u%s%s', self::C_YEAR, $database['clc']));
            $sth->execute(array());
            if ($str = $sth->fetch()) {
                if ($str['value'] == '1') {
                    $is_chiku = true;
                }
            }
            if ($is_chiku == true) {
                // 使用している郵便番号から都道府県コードを取得
                $ken = array();
                $sth_ken = $this->db->query(
                    "SELECT `code_ken` FROM `chiku` INNER JOIN `common_u`.yubin USING (yubincd) GROUP BY `code_ken`"
                );
                while ($str = $sth_ken->fetch()) {
                    $ken[] = $str['code_ken'];
                }
                sort($ken, SORT_NUMERIC);
                $this->databases[$key]['ken'] = $ken;
            } else {
                unset($this->databases[$key]);
            }
        }

    }

    /**
     * 郵便番号データのダウンロード
     * @return bool
     */
    private function getZipData()
    {
        foreach ($this->files as $key => $file) {
            $folder_path = __DIR__ . '/../data/';
            $file_path = $folder_path . $file;
            $csv_file_path = $folder_path . strtoupper(str_replace("zip", "csv", $file));

            // 現存するファイルを消す
            if (is_file($file_path)) {
                unlink($file_path);
            }

            exec(sprintf("wget -O %s http://www.post.japanpost.jp/zipcode/dl/oogaki/zip/%s", $file_path, $file));

            // 既存のcsvファイルを削除
            if (is_file($csv_file_path)) {
                unlink($csv_file_path);
            }

            // 圧縮ファイルを解凍
            $zip = new ZipArchive();
            if ($zip->open($file_path)) {
                if ($zip->extractTo($folder_path)) {
                    $zip->close();
                }
            }

            unlink($file_path);
            if (is_file($csv_file_path)) {
                $this->files[$key] = $csv_file_path;
            } else {
                return false;
            }
        }
        return true;
    }

    private function createExcelFiles()
    {
        // クライアントごとのファイルを作成する
        foreach ($this->databases as $database) {
            // ファイル作成とログの書き出しを任せる
            $create_excel_data = new createExcelData($this->db, $database['clc'], $database['clname'], $this->getChikuSettings($database['clc']), $database['ken']);
            $create_excel_data->createExcelFiles();

            // 作成したファイルのログ
            $this->pushCreateFileLog($database);
        }
    }

    /**
     * @param array $database
     */
    private function pushCreateFileLog($database)
    {
        $file_name = __DIR__ . '../../data/create_file_log';
        $fh = fopen($file_name, "wa");
        fwrite($fh, sprintf("u%s%s\t%s\t%s\n", self::C_YEAR, $database['clc'], $database['clname'], date('Y-m-d H:i:s')));
        fclose($fh);
    }

    private function cleanUpZipData()
    {
        foreach (glob(__DIR__ . "/../data/*.CSV") as $filename) {
            unlink(realpath($filename));
        }
    }

    /**
     * データベース内の地区設定を返します
     * @param string $clc
     * @return array
     */
    private function getChikuSettings($clc)
    {
        // データベースに接続
        $this->db->query(sprintf("USE u%s%s", self::C_YEAR, $clc));
        $sth = $this->db->query("SELECT yubincd,chikucd,chikuname FROM `chiku` LEFT JOIN `chiku_mast` USING (`chikucd`)");
        $rtn = [];
        while ($str = $sth->fetch(PDO::FETCH_ASSOC)) {
            $rtn[$str['yubincd']] = ['chikucd' => $str['chikucd'], 'chikuname' => $str['chikuname']];
        }
        return $rtn;
    }

    private function initLogFile()
    {

        $this->log_file_name = __DIR__ . '/../data/create_file_log';
        // ファイルがある場合は確認してからクリア
        if (file_exists($this->log_file_name)){
            echo("既存のログファイルを削除してもよいですか?(y/N)");
            while (1) {
                $input = fgets(STDIN, 10);
                $input = rtrim($input, "\n");
                if ($input === 'y') {
                    unlink($this->log_file_name);
                    echo "ログファイルを削除して、処理を継続します。\n";
                    break;
                } else {
                    echo "ログファイルをそのままで処理を継続します。\n";
                    break;
                }
            }
        }
    }
}

// arg解析
$options = getopt('h');

if (isset($options['h'])
) {
    print('===============================================================' . "\n");
    print('  createLastYearSettings 使用方法について' . "\n\n");
    print('1. コマンドラインオプション一覧' . "\n");
    print("\t" . '-h このヘルプの表示' . "\n");
    print('2. 動作内容' . "\n");
    print("\t" . '昨年度の地区設定を参照し、今現在の郵便番号データを作成します。' . "\n");
    print('===============================================================' . "\n");
    exit;
}

$create_last_year_settings = new createLastYearSettings();

//// csvファイルの削除
//foreach ($csv_files as $csv) {
//    if (is_file($csv)) {
//        unlink($csv);
//    }
//}

exit;

/**
 * @param array $files
 * @param PDO $db
 * @param string $clientdb
 * @param string $clname
 * @param array $lines
 */
function add_ken($files, $db, $clientdb, $clname, &$lines)
{
    // 都道府県名の格納
    $fuken = array();
    $sth = $db->query('select cd,name from common_u.fuken order by cd');
    while ($str = $sth->fetch()) {
        $fuken[$str['cd']] = $str['name'];
    }

    // 県コードを指定して高校マスタを読み込む
    $sth = $db->prepare(
        "select replace(yubin, '-', '') as yubin,fullname,jusho from koko where ken=? and jusho<> '' and fumei<> '3' order by yubin"
    );

    // 書き出しファイル
//    $output_file = sprintf("yubin_data_%s.txt", $clname);
//    $fout = fopen($output_file, "w");

    $naiyo = array();
    $naiyo_koko = array();

    // 連結スイッチ
    $join = false;

    $max = 0;

    foreach ($files as $kencd => $file) {
        // 読み込みファイル
        $fp = fopen($file, "r");

        while ($str = fgets($fp)) {
            $str = mb_convert_encoding($str, "utf-8", "sjis-win");
            $str = str_replace('"', '', $str);
            $data = explode(",", $str);

            // 郵便番号をキーに
            $key = sprintf("%07d", $data[2]);
            if (!array_key_exists($key, $naiyo)) {
                $naiyo[$key]['ken'] = array();
                $naiyo[$key]['jichi'] = array();
                $naiyo[$key]['chou'] = array();
            }

            // 各配列に値を追加していく
            if (!in_array($data[6], $naiyo[$key]['ken'])) {
                $naiyo[$key]['ken'][] = $data[6];
            }

            if (!in_array($data[7], $naiyo[$key]['jichi'])) {
                $naiyo[$key]['jichi'][] = $data[7];
            }

            // 町域名の中に全角の小かっこが入っていたら文字の連結をするスイッチを入れる
            if (strpos($data[8], "（") !== false) {
                $join = true;
                $chou = "";
            }

            if ($join === true) {
                $chou .= $data[8];
                if (strpos($chou, "）") !== false) {
                    $join = false;
                    $naiyo[$key]['chou'][] = $chou;
                    $chou = "";
                }
            } else {
                if (!in_array($data[8], $naiyo[$key]['chou'])) {
                    $naiyo[$key]['chou'][] = $data[8];
                }
            }

            $max = (count($naiyo[$key]['chou']) > $max) ? count($naiyo[$key]['chou']) : $max;
        }

        fclose($fp);

        // 高校の取得
        $sth->execute(array($kencd));
        while ($str = $sth->fetch()) {
            if (!array_key_exists($str['yubin'], $naiyo)) {
                $naiyo_koko[$str['yubin']]['fuken'] = $fuken[(int)$kencd];
                $naiyo_koko[$str['yubin']]['city'] = $str['fullname'];
                $naiyo_koko[$str['yubin']]['chou'] = $str['jusho'];
            }
        }

    }

    // 郵便番号で地区を調査
    /** @var PDO $db */
    $db->query('use ' . $clientdb);
    /** @var PDOstatement $sth_chiku */
    $sth_chiku = $db->prepare(
        'select chiku.chikucd,chiku_mast.chikuname from chiku inner join chiku_mast using (chikucd) where yubincd=?'
    );
    // 通常郵便番号分
    foreach ($naiyo as $key => $str) {
        $sth_chiku->execute(array($key));

        if ($rec = $sth_chiku->fetch()) {
            $naiyo[$key]['chikucd'] = $rec['chikucd'];
            $naiyo[$key]['chikuname'] = $rec['chikuname'];
        }
    }
    // 高等学校分
    foreach ($naiyo_koko as $key => $str) {
        $sth_chiku->execute(array($key));

        if ($rec = $sth_chiku->fetch()) {
            $naiyo_koko[$key]['chikucd'] = $rec['chikucd'];
            $naiyo_koko[$key]['chikuname'] = $rec['chikuname'];
        } else {
            $naiyo_koko[$key]['chikucd'] = '';
            $naiyo_koko[$key]['chikuname'] = '';
        }
    }

    // 書き出し
    // 最初に項目列を書き出す
    $gyou = "郵便番号\t都道府県\t地区コード\t地区\t市区町村";
    for ($i = 1; $i <= $max; $i++) {
        $gyou = $gyou . "\t地名" . $i;
    }
    $gyou .= "\n";
//    fwrite($fout, $gyou);

    $lines[] = explode("\t", trim($gyou));    // エクセル書き出しのために保存

    // ここから内容の書き出し
    foreach ($naiyo as $key => $str) {
        $ken = implode("・", $str['ken']);
        $jichi = implode("・", $str['jichi']);
        $chikucd = isset($str['chikucd']) ? $str['chikucd'] : '';
        $chikuname = isset($str['chikuname']) ? $str['chikuname'] : '';
        $chou = implode("\t", $str['chou']);
        $gyou = sprintf("%s\t%s\t%s\t%s\t%s\t%s\n", $key, $ken, $chikucd, $chikuname, $jichi, $chou);

//        fwrite($fout, $gyou);

        $lines[] = explode("\t", trim($gyou)); // エクセル書き出しのために保存
    }

    // ここから独自郵便番号の高校の書き出し
    foreach ($naiyo_koko as $key => $str) {
        $gyou = sprintf(
            "%s\t%s\t%s\t%s\t%s\t%s\n",
            $key,
            $str['fuken'],
            $str['chikucd'],
            $str['chikuname'],
            $str['city'],
            $str['chou']
        );

//        fwrite($fout, $gyou);

        $lines[] = explode("\t", trim($gyou)); // エクセル書き出しのために保存
    }

    // ファイルの文字コード変換 utf-8からsjisに
//    $file_contents = file_get_contents($output_file);
//    $file_contents = mb_convert_encoding($file_contents, 'SJIS-win', 'utf-8');
//    file_put_contents($output_file, $file_contents);
}

/**
 * @param string $file_excel
 * @param array $lines
 * @throws PHPExcel_Exception
 * @throws PHPExcel_Reader_Exception
 */
function createExcel($file_excel, $lines)
{

    // 基になるファイルを読み込む
    $objPHPExcel = PHPExcel_IOFactory::load('org/original_data_for_settings.xlsx');

    // シートの選択
    $objPHPExcel->setActiveSheetIndex(0);
    $objSheet = $objPHPExcel->getActiveSheet();

    // 見出し行を固定
    $objSheet->freezePane('A2');

    // データを流し込んでいく
    foreach ($lines as $gyou => $line) {
        foreach ($line as $retsu => $data) {
            if ($retsu == 0) {
                // A列の郵便番号は、文字列としてデータをセットする
                $objSheet->setCellValueExplicitByColumnAndRow(
                    $retsu,
                    $gyou + 1,
                    $data,
                    PHPExcel_Cell_DataType::TYPE_STRING
                );
            } else {
                $objSheet->getCellByColumnAndRow($retsu, $gyou + 1)->setvalue($data);
            }
        }
    }

    $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
    $objWriter->save($file_excel);
    unset($objWriter);
    unset($objSheet);
    unset($objPHPExcel);
}

function not_is_demo($val)
{
    if (preg_match('/(demo|test)/', $val)) {
        return false;
    }
    return true;
}
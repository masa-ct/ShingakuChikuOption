<?php

class adjustPrefecture
{
    /** @var PDO */
    private $server;
    /** @var string */
    private $input;

    public function __construct($server, $input)
    {
        $this->server = $server;
        $this->input = $input;
    }

    /**
     * @return array
     * @throws Exception
     */
    public function getCodes()
    {
        // 入力内容をカンマで分割し配列に格納
        $parts = explode(',', $this->input);

        $sth = $this->server->prepare('SELECT `cd` FROM 18uadmin.fuken WHERE `name`=? OR `shortname`=?');

        $rtn = [];
        $has_error = false;

        foreach ($parts as $part) {
            if (is_numeric($part)) {
                // 数字の場合はコードの範囲内かを判定
                if ((int)$part < 1 || (int)$part > 47) {
                    $has_error = true;
                } else {
                    $rtn[] = (int)$part;
                }
            } else {
                // 数字でない場合は都道府県コードを取得
                $sth->execute([$part, $part]);
                if ($str = $sth->fetch(PDO::FETCH_ASSOC)) {
                    $rtn[] = $str['cd'];
                } else {
                    $has_error = true;
                }
            }
        }

        // エラーのある時は例外を投げる
        if ($has_error) {
            throw new Exception('与えられた都道府県「' . $this->input . '」が不正です。'.PHP_EOL);
        } else {
            return $rtn;
        }
    }
}
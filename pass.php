<?php
function dataMysqlParaPassword(string $dataMysql): array {
    $dt = DateTime::createFromFormat('Y-m-d', $dataMysql);
    if (!$dt) {
        throw new InvalidArgumentException("Data inválida: $dataMysql");
    }
    $password = $dt->format('dmY');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    return ['password' => $password, 'hash' => $hash];
}

$datas = [
    '1990-01-01',
    '1991-01-01',
    '1996-02-26',
    '1988-05-10',
    '1975-11-22',
    '1992-03-15',
    '1980-08-08',
    '2002-09-30',
];

foreach ($datas as $data) {
    $r = dataMysqlParaPassword($data);
    echo "Data: $data → Password: {$r['password']} → Hash: {$r['hash']}<br>";
}
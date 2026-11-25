<?php

namespace App\Enums;

/**
 * `res_labels.label_type`：別名是給人看的，還是只拿來比對。對應 SKOS 的 altLabel、hiddenLabel。
 *
 * 正式名（prefLabel）不在這裡，是 res 自己的 title（技術加上 version，見 `Technique::labelFrom()`）。
 */
enum ResLabelType: string
{
    /** 可以顯示的別名，例如舊正式名、外文寫法（skos:altLabel） */
    case Alt = 'alt';

    /** 只拿來比對、不顯示，例如拼錯、GitHub topic 的寫法（skos:hiddenLabel） */
    case Hidden = 'hidden';
}

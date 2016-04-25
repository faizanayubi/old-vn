<?php

/**
 * Description of publish
 *
 * @author Faizan Ayubi
 */
class Publish extends Shared\Model {

    /**
     * @column
     * @readwrite
     * @type integer
     * @index
     */
    protected $_user_id;

    /**
    * @column
    * @readwrite
    * @type decimal
    * @length 5,2
    */
    protected $_bouncerate;

    /**
    * @column
    * @readwrite
    * @type text
    * @length 32
    *
    * @validate required, alpha, min(3), max(32)
    * @label account
    */
    protected $_account = "basic";

    /**
     * @column
     * @readwrite
     * @type decimal
     * @length 10,2
     *
     * @validate required
     * @label balance
     */
    protected $_balance;

    public function total() {
        $database = \Framework\Registry::get("database");
        $total = $database->query()->from("stats", array("SUM(amount)" => "earn"))->where("user_id=?", $this->user_id)->all();
        return round($total[0]["earn"], 2);
    }
}
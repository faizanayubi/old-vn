<?php

/**
 * @author Faizan Ayubi
 */
use Framework\Registry as Registry;
use Framework\RequestMethods as RequestMethods;
use Framework\ArrayMethods as ArrayMethods;
use \Curl\Curl;

class Analytics extends Manage {
    
    /**
     * @before _secure, changeLayout, _admin
     */
    public function googl() {
        //$this->JSONview();
        $this->noview();
        $view = $this->getActionView();
        
        if (RequestMethods::get("link")) {
            $link_id = RequestMethods::get("link");
            $link = Link::first(array("id = ?" => $link_id), array("id", "short", "item_id", "user_id"));
            if ($link->googl()) {
                $googl = Registry::get("googl");
                $object = $googl->analyticsFull($link->short);
                $view->set("googl", $object);
                echo "<pre>", print_r($object), "</pre>";
            }
            $view->set("link", $link);
        }
    }

    /**
     * @before _secure, changeLayout, _admin
     */
    public function content($id='') {
        $this->seo(array("title" => "Content Analytics", "view" => $this->getLayoutView()));
        $view = $this->getActionView();

        $item = Item::first(array("id = ?" => $id));

        $earn = 0;
        $stats = Stat::all(array("item_id = ?" => $item->id), array("amount"));
        foreach ($stats as $stat) {
            $earn += $stat->amount;
        }

        $links = Link::count(array("item_id = ?" => $item->id));
        $rpm = RPM::count(array("item_id = ?" => $item->id));

        $view->set("item", $item);
        $view->set("earn", $earn);
        $view->set("links", $links);
        $view->set("rpm", $rpm);
    }

    /**
     * @before _secure, changeLayout, _admin
     */
    public function urlDebugger() {
        $this->seo(array("title" => "URL Debugger", "view" => $this->getLayoutView()));
        $view = $this->getActionView();

        $url = RequestMethods::get("urld", "http://clicks99.com/");
        $metas = get_meta_tags($url);

        $facebook = new Curl();
        $facebook->get('https://api.facebook.com/method/links.getStats', array(
            'format' => 'json',
            'urls' => $url
        ));
        $facebook->setOpt(CURLOPT_ENCODING , 'gzip');
        $facebook->close();

        $twitter = new Curl();
        $twitter->get('https://cdn.api.twitter.com/1/urls/count.json', array(
            'url' => $url
        ));
        $twitter->setOpt(CURLOPT_ENCODING , 'gzip');
        $twitter->close();

        $view->set("url", $url);
        $view->set("metas", $metas);
        $view->set("facebook", array_values($facebook->response)[0]);
        $view->set("twitter", $twitter->response);
    }

    /**
     * @before _secure
     */
    public function link($date = NULL) {
        $this->JSONview();
        $view = $this->getActionView();

        $link_id = RequestMethods::get("link");
        $link = Link::first(array("id = ?" => $link_id), array("item_id", "id"));
        if (!$link || $link->user_id != $this->user->id) {
            $this->redirect("/404");
        }
        $result = $link->stat($date);
        
        $view->set("earning", $result["earning"]);
        $view->set("click", $result["click"]);
        $view->set("rpm", $result["rpm"]);
        $view->set("analytics", $result["analytics"]);
        $view->set("link", $link);
    }

    /**
     * @before _secure
     */
    public function item($date = NULL) {
        $this->JSONview();
        $view = $this->getActionView();

        $item_id = RequestMethods::get("item_id");
        $item = Item::first(array("id = ?" => $item_id), array("id"));
        if (!$item || $item->user_id != $this->user->id) {
            $this->redirect("/404");
        }
        $result = $item->stats($date);
        
        $view->set("earning", $result["earning"]);
        $view->set("click", $result["click"]);
        $view->set("rpm", $result["rpm"]);
        $view->set("analytics", $result["analytics"]);
        $view->set("item", $item);
    }

    /**
     * @before _secure, changeLayout
     */
    public function logs($action = "", $name = "") {
        $this->seo(array("title" => "Activity Logs", "view" => $this->getLayoutView()));
        $view = $this->getActionView();

        if ($action == "unlink") {
            $file = APP_PATH ."/logs/". $name . ".txt";
            @unlink($file);
            $this->redirect("/analytics/logs");
        }

        $logs = array();
        $path = APP_PATH . "/logs";
        $iterator = new DirectoryIterator($path);

        foreach ($iterator as $item) {
            if (!$item->isDot()) {
                if (substr($item->getFilename(), 0, 1) != ".") {
                    array_push($logs, $item->getFilename());
                }
            }
        }
        arsort($logs);
        $view->set("logs", $logs);
    }

    /**
     * @before _secure, changeLayout, _admin
     */
    public function clicks() {
        $this->seo(array("title" => "Clicks Stats", "view" => $this->getLayoutView()));
        $view = $this->getActionView();

        $now = strftime("%Y-%m-%d", strtotime('now'));
        $view->set("now", $now);
    }

    /**
     * Today Stats of user
     * @return array earnings, clicks, rpm, analytics
     * @before _secure
     */
    public function stats($created = NULL, $auth = 1, $user_id = NULL, $item_id = NULL) {
        $this->seo(array("title" => "Stats", "view" => $this->getLayoutView()));
        $view = $this->getActionView();
        $total_click = 0;$earning = 0;$analytics = array();$query = array();$publishers = array();
        $rpm = array("IN" => 135, "US" => 220, "CA" => 220, "AU" => 220, "GB" => 220, "NONE" => 80);
        $return = array("click" => 0, "rpm" => 0, "earning" => 0, "analytics" => array());

        is_null($created) ? NULL : $query['created'] = $created;
        is_null($item_id) ? NULL : $query['item_id'] = $item_id;
        if ($auth) {
            $query['user_id'] = (is_null($user_id) ? $this->user->id : $user_id);
        }

        $collection = Registry::get("MongoDB")->clicks;

        $cursor = $collection->find($query);
        foreach ($cursor as $id => $result) {
            $code = $result["country"];
            $total_click += $result["click"];
            if (array_key_exists($code, $rpm)) {
                $earning += ($rpm[$code])*($result["click"])/1000;
            } else {
                $earning += ($rpm["NONE"])*($result["click"])/1000;
            }
            if (array_key_exists($code, $analytics)) {
                $analytics[$code] += $result["click"];
            } else {
                $analytics[$code] = $result["click"];
            }
            if (array_key_exists($result["user_id"], $publishers)) {
                $publishers[$result["user_id"]] += $result["click"];
            } else {
                $publishers[$result["user_id"]] = $result["click"];
            }
        }
        $publishers = $this->array_sort($publishers, 'click', SORT_DESC);$rank = array();
        foreach ($publishers as $key => $value) {
            array_push($rank, array(
                "user_id" => $key,
                "clicks" => $value
            ));
        }
        arsort($analytics);
        arsort($publishers);

        if ($total_click > 0) {
            $return = array(
                "click" => round($total_click),
                "rpm" => round($earning*1000/$total_click, 2),
                "earning" => round($earning, 2),
                "analytics" => $analytics,
                "publishers" => $rank
            );
        }

        $view->set("stats", $return);
    }

    protected function validateDate($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') == $date;
    }


    /**
     * Analytics of Single Campaign Datewise
     * @return array earnings, clicks, rpm, analytics
     * @before _secure
     */
    public function campaign($created = NULL, $item_id = NULL) {
        $this->seo(array("title" => "Stats", "view" => $this->getLayoutView()));
        $view = $this->getActionView();
        $total_click = 0;$earning = 0;$analytics = array();$query = array();$i = array();
        $return = array("click" => 0, "rpm" => 0, "earning" => 0, "analytics" => array());

        if ($this->validateDate($created)) {
            $query['created'] = $created;
        }
        if (is_null($item_id)) {
            $items = Item::all(array("user_id = ?" => $this->user->id), array("id"));
            foreach ($items as $item) {
                $i[] = $item->id;
            }
            $query['item_id'] = array('$in' => $i);
        } else {
            $i = Item::first(array("id = ?" => $item_id, "user_id = ?" => $this->user->id));
            $query['item_id'] = $i->id;
        }
        
        $collection = Registry::get("MongoDB")->clicks;

        $cursor = $collection->find($query);
        foreach ($cursor as $id => $result) {
            //echo "<pre>", print_r($result), "</pre>";
            $rpms = RPM::first(array("item_id = ?" => $result["item_id"]), array("value"));
            $rpm = json_decode($rpms->value, true);
            $code = $result["country"];
            $total_click += $result["click"];
            if (array_key_exists($code, $rpm)) {
                $earning += ($rpm[$code])*($result["click"])/1000;
            } else {
                $earning += ($rpm["NONE"])*($result["click"])/1000;
            }
            if (array_key_exists($code, $analytics)) {
                $analytics[$code] += $result["click"];
            } else {
                $analytics[$code] = $result["click"];
            }
            
        }

        if ($total_click > 0) {
            $return = array(
                "click" => round($total_click),
                "rpm" => round($earning*1000/$total_click, 2),
                "earning" => round($earning, 2),
                "analytics" => $analytics
            );
        }

        $view->set("stats", $return);
        $view->set("query", $query);
    }

    /**
     * @before _secure, changeLayout, _admin
     */
    public function verify($user_id) {
        $this->seo(array("title" => "Verify Stats", "view" => $this->getLayoutView()));
        $view = $this->getActionView();

        $page = RequestMethods::get("page", 1);
        $limit = RequestMethods::get("limit", 10);
        
        $stats = Stat::all(array("user_id = ?" => $user_id), array("*"), "amount", "desc", $limit, $page);
        
        $view->set("stats", $stats);
        $view->set("limit", $limit);
        $view->set("page", $page);
        $view->set("count", Stat::count(array("user_id = ?" => $user_id)));
    }

    /**
     * @before _secure, changeLayout, _admin
     */
    public function delduplicate($stat_id) {
        $this->noview();
        
        $stat = Stat::first(array("id = ?" => $stat_id));
        $link = Link::first(array("id = ?" => $stat->link_id));
        $account = Account::first(array("user_id = ?" => $stat->user_id));
        if ($link->delete()) {
            $account->balance -= $stat->amount;
            $account->save();
            $stat->delete();
        }
        
        $this->redirect($_SERVER['HTTP_REFERER']);
    }

    /**
     * @before _secure, changeLayout
     */
    public function reports() {
        $this->noview();
        $date = date('Y-m-d', strtotime("now"));
        $yesterday = date('Y-m-d', strtotime("-1 Day"));
        $output = fopen('php://output', 'w');

        fputcsv($output, array('Link', 'Clicks', 'Amount', 'RPM', 'Earning'));
        $m = Registry::get("MongoDB")->urls;
        $links = $m->find(array('user_id' => $this->user->id, "created < ?" => $now));
        foreach ($links as $key => $value) {
            $link = Link::first(array("id = ?" => $value["link_id"]), array("short", "id", "item_id"));
            $stat = Stat::first(array("link_id = ?" => $value["link_id"]), array("click", "amount", "rpm"));
            if (isset($stat)) {
                fputcsv($output, array($link->short, $stat->click, $stat->amount, $stat->rpm, "Added"));
            } else {
                if ($link) {
                    $data = $link->stat($yesterday);
                    fputcsv($output, array($link->short, $data["click"], $data["amount"], $data["rpm"], "Not Added, Sessions less than 10"));
                }
            }
        }
        header('Content-Description: File Transfer');
        header("Content-Type: text/csv; charset=utf-8");
        header("Content-Disposition: attachment; filename=report{$this->user->id}_{$yesterday}.csv");
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        $done = fclose($output);
        exit;
    }

    protected static function _records($user_id, $opts) {
        $advert = Advert::first(["user_id = ?" => $user_id]); $token = $advert->gatoken;
        $user = ArrayMethods::toObject(['id' => $user_id]);

        $client = Shared\Services\GA::client($token);
        $records = Shared\Services\GA::liveStats($client, $user, $opts);
        return $records;
    }

    /**
     * @before _secure, changeLayout, _admin
     */
    public function publisher() {
        $this->seo(array("title" => "Platform GA Stats", "view" => $this->getLayoutView()));
        $view = $this->getActionView();
        $user_id = RequestMethods::get("user");

        $result = []; $count = 0;
        $clicks = Registry::get("MongoDB")->clicks; $totalClicks = 0;
        if ($user_id) {
            $start = RequestMethods::get("startdate", date('Y-m-d', strtotime("-7 day")));
            $end = RequestMethods::get("enddate", date('Y-m-d'));
            
            $record_1 = $this->_records(1, ['start' => $start, 'end' => $end]);
            $record_2 = $this->_records(725, ['start' => $start, 'end' => $end]);
            $records = array_merge($record_1, $record_2);

            $start_time = strtotime($start); $end_time = strtotime($end);
            for ($i = 0; $start_time < $end_time; $i++) {
                $start_time = strtotime($start . " +{$i} day");
                $date = date('Y-m-d', $start_time);
                $clicks_records = $clicks->find(['user_id' => $user_id, 'created' => $date], ['click' => true]);

                foreach ($clicks_records as $c) {
                    $totalClicks += $c['click'];
                }
            }
            
            foreach ($records as $r) {
                if ($r['source'] != $user_id) continue;

                $result[] = ArrayMethods::toObject($r);
                $count++;
            }    
        }
        
        $view->set("records", $result)
            ->set("totalClicks", $totalClicks)
            ->set("user_id", $user_id);
    }
}

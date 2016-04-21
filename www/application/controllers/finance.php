<?php

/**
 * Description of analytics
 *
 * @author Faizan Ayubi
 */
use Framework\Registry as Registry;
use Framework\RequestMethods as RequestMethods;
use \Curl\Curl;
use Snappy\Pdf;

class Finance extends Admin {

    /**
     * All earnings records of persons
     * 1 - paid, 0 - unpaid
     * 
     * @before _secure, changeLayout, _admin
     */
    public function pending() {
        $this->seo(array("title" => "Records Finance", "view" => $this->getLayoutView()));
        $view = $this->getActionView();
        $database = Registry::get("database");$where = array();$today = date('Y-m-d', strtotime("now"));
        $live = RequestMethods::get("live", 0);
        $page = RequestMethods::get("page", 1);
        $limit = RequestMethods::get("limit", 10);
        $user_id = RequestMethods::get("user_id");
        if ($user_id) {
            $where = array("user_id = ?" => RequestMethods::get("user_id"));
        }
        
        $accounts = Account::all($where, array("user_id", "balance"), "balance", "desc", $limit, $page);
        
        $view->set("accounts", $accounts);
        $view->set("count", Account::count($where));
        $view->set("page", $page);
        $view->set("limit", $limit);
        $view->set("live", $live);
        $view->set("user_id", $user_id);
        $view->set("today", $today);
    }

    /**
     * @before _secure, changeLayout, _admin
     */
    public function makepayment($user_id) {
        $this->seo(array("title" => "Make Payment", "view" => $this->getLayoutView()));
        $view = $this->getActionView();
        $payee = User::first(array("id = ?" => $user_id), array("id", "name", "email", "phone"));
        $account = Account::first(array("user_id = ?" => $user_id));
        if (!$account) {
            $account = new Account(array(
                "user_id" => $user_id,
                "balance" => 0,
                "live" => 1
            ));
            $account->save();
        }
        $bank = Bank::first(array("user_id = ?" => $user_id), array("*"), "created", "desc");
        $paypal = Paypal::first(array("user_id = ?" => $user_id), array("*"), "created", "desc");
        $paytm = Paytm::first(array("user_id = ?" => $user_id), array("*"), "created", "desc");

        if (RequestMethods::post("action") == "payment") {
            switch (RequestMethods::post("live")) {
                case '0':
                    $transaction = new Transaction(array(
                        "user_id" => $user_id,
                        "amount" => RequestMethods::post("amount"),
                        "ref" => RequestMethods::post("ref"),
                        "live" => 0
                    ));
                    $account->balance += RequestMethods::post("amount");
                    $this->notify(array(
                        "template" => "accountCredited",
                        "subject" => "Payment Received",
                        "user" => $payee,
                        "transaction" => $transaction,
                        "bank" => $bank
                    ));
                    break;
                case '1':
                    $transaction = new Transaction(array(
                        "user_id" => $user_id,
                        "amount" => -(RequestMethods::post("amount")),
                        "ref" => RequestMethods::post("ref"),
                        "live" => 1
                    ));
                    $account->balance -= RequestMethods::post("amount");
                    $this->notify(array(
                        "template" => "accountDebited",
                        "subject" => "Payments From Clicks99 Team",
                        "user" => $payee,
                        "transaction" => $transaction,
                        "bank" => $bank
                    ));
                    break;
                case '2':
                    $transaction = new Transaction(array(
                        "user_id" => $user_id,
                        "amount" => -(RequestMethods::post("amount")),
                        "ref" => RequestMethods::post("ref"),
                        "live" => 1
                    ));
                    $account->balance -= RequestMethods::post("amount");
                    $this->notify(array(
                        "template" => "accountDeducted",
                        "subject" => "Amount Deducted From Clicks99 Account",
                        "user" => $payee,
                        "transaction" => $transaction
                    ));
                    break;
            }
            $transaction->save();
            $account->save();

            $this->redirect("/finance/transactions.html?property=user_id&value={$payee->id}");
        }

        $view->set("payee", $payee);
        $view->set("account", $account);
        $view->set("bank", $bank);
        $view->set("paytm", $paytm);
        $view->set("paypal", $paypal);
    }

    /**
     * Earning on a Content
     * @before _secure, changeLayout, _admin
     */
    public function content($id='') {
        $this->seo(array("title" => "Content Finance", "view" => $this->getLayoutView()));
        $view = $this->getActionView();

        $item = Item::first(array("id = ?" => $id));

        $earn = 0;
        $earnings = Earning::all(array("item_id = ?" => $item->id), array("amount"));
        foreach ($earnings as $earning) {
            $earn += $earning->amount;
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
    public function transactions() {
        $this->seo(array("title" => "Transactions", "view" => $this->getLayoutView()));
        $view = $this->getActionView();

        $page = RequestMethods::get("page", 1);
        $limit = RequestMethods::get("limit", 10);
        $property = RequestMethods::get("property", "live");
        $value = RequestMethods::get("value", 0);

        $where = array("{$property} = ?" => $value);
        $transactions = Transaction::all($where, array("*"), "created", "desc", $limit, $page);
        $count = Transaction::count($where);

        $view->set("transactions", $transactions);
        $view->set("limit", $limit);
        $view->set("page", $page);
        $view->set("count", $count);
        $view->set("property", $property);
        $view->set("value", $value);
    }

    /**
     * @before _secure
     */
    public function credit() {
        $this->JSONview();
        $view = $this->getActionView();
        $configuration = Registry::get("configuration");
        $amount = RequestMethods::post("amount");
        if ($amount < 9999) {
            $view->set("error", "Amount less than minimum amount");
            die();
        }
        if (RequestMethods::post("action") == "credit") {
            $imojo = $configuration->parse("configuration/payment");
            $curl = new Curl();
            $curl->setHeader('X-Api-Key', $imojo->payment->instamojo->key);
            $curl->setHeader('X-Auth-Token', $imojo->payment->instamojo->auth);
            $curl->post('https://www.instamojo.com/api/1.1/payment-requests/', array(
                "purpose" => "Advertisement",
                "amount" => $amount,
                "buyer_name" => $this->user->name,
                "email" => $this->user->email,
                "phone" => $this->user->phone,
                "redirect_url" => "http://clicks99.com/finance/success",
                "allow_repeated_payments" => false
            ));

            $payment = $curl->response;
            if ($payment->success == "true") {
                $instamojo = new Instamojo(array(
                    "user_id" => $this->user->id,
                    "payment_request_id" => $payment->payment_request->id,
                    "amount" => $payment->payment_request->amount,
                    "status" => $payment->payment_request->status,
                    "longurl" => $payment->payment_request->longurl,
                    "live" => 0
                ));
                $instamojo->save();
                $view->set("success", true);
                $view->set("payurl", $instamojo->longurl);
            } else {
                $this->redirect("/");
            }
        }
    }

    public function success() {
        $this->seo(array("title" => "Thank You", "view" => $this->getLayoutView()));
        $view = $this->getActionView();
        $configuration = Registry::get("configuration");
        $payment_request_id = RequestMethods::get("payment_request_id");

        if ($payment_request_id) {
            $instamojo = Instamojo::first(array("payment_request_id = ?" => $payment_request_id));

            if ($instamojo) {
                $imojo = $configuration->parse("configuration/payment");
                $curl = new Curl();
                $curl->setHeader('X-Api-Key', $imojo->payment->instamojo->key);
                $curl->setHeader('X-Auth-Token', $imojo->payment->instamojo->auth);
                $curl->get('https://www.instamojo.com/api/1.1/payment-requests/'.$payment_request_id.'/');
                $payment = $curl->response;

                $instamojo->status = $payment->payment_request->status;
                if ($instamojo->status == "Completed") {
                    $instamojo->live = 1;
                }
                $instamojo->save();

                $user = User::first(array("id = ?" => $instamojo->user_id));

                $transaction = new Transaction(array(
                    "user_id" => $instamojo->user_id,
                    "amount" => $instamojo->amount,
                    "ref" => $instamojo->payment_request_id
                ));
                $transaction->save();

                $account = Account::first(array("user_id = ?" => $user_id));
                if (!$account) {
                    $account = new Account(array(
                        "user_id" => $instamojo->user_id,
                        "balance" => 0,
                        "live" => 1
                    ));
                    $account->save();
                }

                $account->balance += $instamojo->amount;
                $account->save();

                $this->notify(array(
                    "template" => "accountCredited",
                    "subject" => "Payment Received",
                    "user" => $user,
                    "transaction" => $transaction
                ));
            } else {
                $this->redirect("/404");
            }
        }
    }

    /**
     * @before _secure
     */
    public function payout() {
        $this->JSONview();
        $view = $this->getActionView();

        $payout = Payout::first(array("user_id = ?" => $this->user->id));
        if (isset($payout)) {
            $payout->live = true;
        } else {
            $payout = new Payout(array(
                "user_id" => $this->user->id,
                "live" => 1
            ));
        }
        $payout->save();
        $view->set("payout", $payout);
    }
    
}

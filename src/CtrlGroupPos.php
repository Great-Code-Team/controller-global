<?php

namespace Greatcode\ControllerGlobal;

use Exception;
use GuzzleHttp\Client;

class CtrlGroupPos
{
    /**
     * @var string
     */
    private $pc_location;

    /**
     * @param string
     */
    private $integrator_url;

    function __construct($pc_location, $integrator_url)
    {
        $this->pc_location = $pc_location;
        $this->integrator_url = $integrator_url;
    }

    /**
     * send http get method
     * 
     * @param string $url
     * @return array
     * @throws Exception
     */
    private function sendGet(string $url)
    {
        $client = new Client();
        $response = $client->get($url);
        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        return is_array($data) ? $data : ['response' => $body];
    }

    /**
     * get group pos
     * 
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function getGroupPos($params)
    {
        if (!is_array($params)) {
            return ['error' => 'Params must be array'];
        }

        foreach (['group_pos', 'browser', 'waktu'] as $key) {
            if (empty($params[$key])) {
                return ['error' => "`$key` must not be empty"];
            }
        }

        if (!isset($params['pc_location'])) {
            $params['pc_location'] = $this->pc_location;
        }

        $url = $this->integrator_url . "/server/svr_pos_user.php?" . http_build_query($params);
        return $this->sendGet($url);
    }

    /**
     * update login process
     * 
     * @param string $id
     * @param string $status
     * @return array
     * @throws Exception
     */
    public function updateLoginProcess($id, $status)
    {
        if (empty($id) || empty($status)) {
            return ['error' => '`id` or `status` cant be empty'];
        }

        $params = [
            'act' => 'updateLogin',
            'id' => $id,
            'status' => $status,
        ];

        $url = $this->integrator_url . "/server/svr_pos_user.php?" . http_build_query($params);
        return $this->sendGet($url);
    }

    /**
     * update last date
     * 
     * @param string $id
     * @param string $last_data
     * @return array
     * @throws Exception
     */
    public function updateLastDate($id, $last_data)
    {
        if (empty($last_data)) {
            return ['error' => '`last_data` must not be empty'];
        }
        $params = [
            "act" => "updatePosLastDate",
            "id" => $id,
            "last_data" => urlencode($last_data),
            "last_pool" => urlencode(date("Y-m-d H:i:s"))
        ];
        $url = $this->integrator_url . "/server/svr_pos_user.php?" . http_build_query($params);
        $this->sendGet($url);
    }

    /**
     * update pos last date
     * 
     * @param array $datas
     * @return array
     */
    public function updatePOSLastDate($datas)
    {
        if (!is_array($datas)) {
            return ['error' => "`datas` must be array of array"];
        }

        $required_fields = [
            'status',
            'data'
        ];
        foreach ($datas as $data) {
            foreach ($required_fields as $idx => $field) {
                if (empty($data[$field])) {
                    return ['error' => "{$field} is empty in index: $idx"];
                }
            }
        }

        $url = $this->integrator_url . "/server/svr_group_pos.php?act=updatePos";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($datas),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
        ]);

        $res = curl_exec($ch);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['error' => "Curl error: $err"];
        }

        curl_close($ch);
        return ['success' => true, 'response' => $res];
    }

    /**
     * update pos token
     * 
     * @param string $token
     * @param string $id
     * @param string $status
     * @return array
     * @throws Exception
     */
    public function updatePosToken($token, $id, $status)
    {
        foreach (['id' => $id, 'status' => $status] as $key => $value) {
            if (empty($value)) {
                return ['error' => "updatePosToken error: '$key' must not be empty"];
            }
        }

        $params = [
            'act' => 'updatePosToken',
            'token' => $token,
            'id' => $id,
            // 'browser' => $browser,
            'pc_location' => $this->pc_location,
            'status' => $status,
        ];

        $url = $this->integrator_url . "/server/svr_pos_user.php?" . http_build_query($params);

        return $this->sendGet($url);
    }

    /**
     * update branch id
     * 
     * @param string $branchID
     * @param string $id
     * @return array
     * @throws Exception
     */
    public function updateBranchId($branchID, $id)
    {
        foreach (['id' => $id, 'branchID' => $branchID] as $key => $value) {
            if (empty($value)) {
                return ['error' => "updatePosToken error: '$key' must not be empty"];
            }
        }

        $params = [
            'act' => 'updateBranchID',
            'branchID' => $branchID,
            'id' => $id,
        ];

        $url = $this->integrator_url . "/server/svr_pos_user.php?" . http_build_query($params);

        return $this->sendGet($url);
    }

    /**
     * save to local
     * 
     * @param string $table
     * @param array $arFieldValues
     * @return bool
     * @throws Exception
     */
    public function saveToLocal($table, $arFieldValues)
    {
        try {
            $ctrl = CtrlGlobal::getInstance();
            $result = $ctrl->insertAll($table, $arFieldValues);
            return "success" == $result;
        } catch (Exception $e) {
            return false;
        }
    }
}

<?php

namespace Greatcode\ControllerGlobal;

use Exception;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

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

    /**
     * CtrlGlobal instance.
     * 
     * @var CtrlGlobal
     */
    private CtrlGlobal $ctrl_global;

    /**
     * Create a new CtrlGroupPos instance.
     * 
     * @param string $pc_location
     * @param string $integrator_url
     * @param CtrlGlobal|null $ctrl_global
     */
    function __construct(string $pc_location, string $integrator_url, CtrlGlobal|null $ctrl_global = null)
    {
        $this->pc_location = $pc_location;
        $this->integrator_url = $integrator_url;
        $this->ctrl_global = $ctrl_global ?? CtrlGlobal::getInstance();
    }

    /**
     * send http get method
     * 
     * @param ResponseInterface $url
     * @return array
     * @throws Exception
     */
    private function parseResponse(ResponseInterface $url)
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
        $response = $this->ctrl_global->httpGet($url);
        return $this->parseResponse($response);
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
        $response = $this->ctrl_global->httpGet($url);
        return $this->parseResponse($response);
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
        $response = $this->ctrl_global->httpGet($url);
        return $this->parseResponse($response);
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

        $response = $this->ctrl_global->httpPost($url, $datas);
        $body = $response->getBody()->getContents();
        return ['success' => true, 'response' => $body];
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
        $response = $this->ctrl_global->httpGet($url);
        return $this->parseResponse($response);
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
        $response = $this->ctrl_global->httpGet($url);
        return $this->parseResponse($response);
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

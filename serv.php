<?php

require './vendor/autoload.php';
require './config.php';

// 初始化
$httpClient = new GuzzleHttp\Client();
$client = new fXmlRpc\Client(
    $apiUrl,
    new fXmlRpc\Transport\HttpAdapterTransport(
        new \Http\Message\MessageFactory\DiactorosMessageFactory(),
        new \Http\Adapter\Guzzle6\Client($httpClient)
    )
);

header('Content-type:text/json');
if (isset($_GET['action'])) {
    $act = strtolower($_GET['action']);
    switch ($act) {
        case 'cl': // 会议列表
            echo json_encode(getConferenceList());
            break;
        case 'cc': // 开启会议
            echo json_encode(createConference($_GET['conferenceName']));
            break;
        case 'ce': // 关闭会议
            echo json_encode(destroyConference($_GET['conferenceName']));
            break;
        case 'cd': // 修改会议
            echo json_encode(changeConferenceLayout($_POST['factoryConferenceId'], $_POST['customLayoutEnabled'], $_POST['newParticipantsCustomLayout'], $_POST['customLayout']));
            break;
        case 'pl': // 指定会议与会者列表
            echo json_encode(getParticipantList(explode(',', $_GET['factoryConferenceIds'])));
            break;
        case 'pc': // 添加与会者
            $protocol = strtolower(str_replace('.', '', $_GET['participantProtocol']));
            echo json_encode(addParticipant($_GET['conferenceName'], $_GET['participantName'], $_GET['address'], $protocol));
            break;
        case 'pr': // 移除与会者
            $protocol = strtolower(str_replace('.', '', $_GET['participantProtocol']));
            echo json_encode(disconnectParticipant($_GET['conferenceName'], $_GET['participantName'], $protocol, $_GET['participantType']));
            break;
        case 'pa': // 修改与会者音频状态
            $protocol = strtolower(str_replace('.', '', $_GET['participantProtocol']));
            echo json_encode(muteParticipantAudio($_GET['conferenceName'], $_GET['participantName'], $_GET['audioRxMuted'], $protocol, $_GET['participantType']));
            break;
        case 'pv': // 修改与会者视频状态
            $protocol = strtolower(str_replace('.', '', $_GET['participantProtocol']));
            echo json_encode(muteParticipantVideo($_GET['conferenceName'], $_GET['participantName'], $_GET['videoRxMuted'], $protocol, $_GET['participantType']));
            break;
        case 'pn': // 修改与会者显示名称
            $protocol = strtolower(str_replace('.', '', $_GET['participantProtocol']));
            echo json_encode(modifyParticipantDisplayName($_GET['conferenceName'], $_GET['participantName'], $_GET['displayNameOverrideStatus'], $_GET['displayNameOverrideValue'], $protocol, $_GET['participantType']));
            break;
        case 'ps': // 修改与会者显示名称
            $protocol = strtolower(str_replace('.', '', $_GET['participantProtocol']));
            echo json_encode(messageParticipant($_GET['conferenceName'], $_GET['participantName'], $_GET['message'], $protocol, $_GET['participantType']));
            break;
        default:
            echo json_encode(array('success' => false, 'msg' => '未知操作'));
            break;
    }

}
exit;

/**
 * 创建会议
 * @param  string  $confName   会议名
 * @param  string  $mainPin    主席密码
 * @param  string  $guestPin   会议密码
 * @param  integer $minDurMins 最小会议时间
 * @param  integer $maxDurMins 最大会议时间，0表示无限长
 * @return string 会议ID
 */
function createConference($confName, $mainPin = '', $guestPin = '', $minDurMins = 5, $maxDurMins = 0) {
    global $authArray;
    global $client;

    // 默认结果
    $success = false;
    $msg = "会议创建失败";

    try {
        $requestParams = array(
            'conferenceAlias'           => $confName,
            'factoryMinDurationMinutes' => $minDurMins,
            'factoryMaxDurationMinutes' => $maxDurMins,
            'factoryOverridePIN'        => $mainPin,
            'factoryOverrideGuestPIN'   => $guestPin,
        );
        $requestParams = array(array_merge($authArray, $requestParams));
        cplog($requestParams, '请求参数'); // TODO: Chrome Log

        $response = $client->call('factory.conferencecreate', $requestParams);
        cplog($response, '响应结果'); // TODO: Chrome Log

        if ($response['status'] && STATUS_SUCCESS == $response['status']) {
            $success = true;
            $conferenceId = $response['factory_conference_id'];

            return compact("success", "conferenceId");
        }
    } catch (fXmlRpc\Exception\FaultException $fex) {
        cplog($fex, '接口请求异常'); // TODO: Chrome Log
        $msg = strpos($fex->getFaultString(), 'conference already present') !== false ? '会议名称重复，创建失败' : ($fex->getFaultCode() . ' ' . $fex->getFaultString());
    } catch (Exception $ex) {
        cplog($ex, '未知异常'); // TODO: Chrome Log
        $msg = $ex->getCode() . ' ' . $ex->getMessage();
    }

    return compact("success", "msg");
}

/**
 * 在线会议列表
 * @return void
 */
function getConferenceList() {
    global $authArray;
    global $client;

    // 默认结果
    $success = false;
    $msg = "未取到有效数据";

    try {
        $requestParams = array($authArray);
        cplog($requestParams, '请求参数'); // TODO: Chrome Log

        $response = $client->call('conference.enumerate', $requestParams);
        cplog($response); // TODO: Chrome Log

        if (isset($response['conferences']) || isset($response['status']) && STATUS_SUCCESS == $response['status']) {
            $success = true;
            $conferences = $response['conferences'];
            return compact("success", "conferences");
        }
    } catch (fXmlRpc\Exception\FaultException $fex) {
        cplog($fex, '接口请求异常'); // TODO: Chrome Log
        $msg = $fex->getFaultCode() . ' ' . $fex->getFaultString();
    } catch (Exception $ex) {
        cplog($ex, '未知异常'); // TODO: Chrome Log
        $msg = $ex->getCode() . ' ' . $ex->getMessage();
    }

    return compact("success", "msg");
}


/**
 * 设定会议布局
 * @param  string $confName                    会议名称
 * @param  bool   $customLayoutEnabled         允许改变布局
 * @param  bool   $newParticipantsCustomLayout 允许新加入的与会者使用已经改变的布局
 * @param  int    $customLayout                布局类型，0为无布局，1为单主会场布局，2为平铺布局，5为主分会场布局
 * @return bool 成功返回true，失败返回false或者错误信息
 */
function changeConferenceLayout($confName, $customLayoutEnabled, $newParticipantsCustomLayout, $customLayout) {
    global $authArray;
    global $client;

    // 默认结果
    $success = false;
    $msg = "未取到有效数据";

    // 检查布局类型是否有效
    if (!in_array($customLayout, array(0, 1, 2, 5))) {
        $msg = "无效的布局类型";

        return compact("success", "msg");
    }

    try {
        $requestParams = array(
            'conferenceName'              => $confName,
            'customLayoutEnabled'         => $customLayoutEnabled?1:0,
            'newParticipantsCustomLayout' => $newParticipantsCustomLayout?1:0,
            'customLayout'                => $customLayout
        );
        $requestParams = array(array_merge($authArray, $requestParams));
        cplog($requestParams, '请求参数'); // TODO: Chrome Log

        $response = $client->call('conference.modify', $requestParams);
        cplog($response, '响应结果'); // TODO: Chrome Log

        if ($response['status'] && STATUS_SUCCESS == $response['status']) {
            $success = true;
            $result = $response;

            return compact("success", "result");
        }
    } catch (fXmlRpc\Exception\FaultException $fex) {
        cplog($fex, '接口请求异常'); // TODO: Chrome Log
        $msg = $fex->getFaultCode() . ' ' . $fex->getFaultString();
    } catch (Exception $ex) {
        cplog($ex, '未知异常'); // TODO: Chrome Log
        $msg = $ex->getCode() . ' ' . $ex->getMessage();
    }

    return compact("success", "msg");
}

/**
 * 删除会议
 * @param  string $confName 会议名称
 * @return bool
 */
function destroyConference($confName) {
    global $authArray;
    global $client;

    // 默认结果
    $success = false;
    $msg = "未取到有效数据";

    try {
        $requestParams = array('conferenceName' => $confName);
        $requestParams = array(array_merge($authArray, $requestParams));
        cplog($requestParams, '请求参数'); // TODO: Chrome Log

        $response = $client->call('conference.destroy', $requestParams);
        cplog($response, '响应结果'); // TODO: Chrome Log

        if ($response['status'] && STATUS_SUCCESS == $response['status']) {
            $success = true;
            $msg = "会议已关闭";
        }
    } catch (fXmlRpc\Exception\FaultException $fex) {
        cplog($fex, '接口请求异常'); // TODO: Chrome Log
        $msg = strpos($fex->getFaultString(), 'not found') !== false ? '会议不存在' : ($fex->getFaultCode() . ' ' . $fex->getFaultString());
    } catch (Exception $ex) {
        cplog($ex, '未知异常'); // TODO: Chrome Log
        $msg = $ex->getCode() . ' ' . $ex->getMessage();
    }

    return compact("success", "msg");
}


/**
 * 获取与会者列表
 * @param  array $factoryConferenceIds 会议ID数组
 * @return void
 */
function getParticipantList($factoryConferenceIds) {
    global $authArray;
    global $client;

    // 默认结果
    $success = false;
    $msg = "未取到有效数据";

    try {
        if ($factoryConferenceIds) {
            $requestParams = array('factoryConferenceIds' => $factoryConferenceIds);
        } else {
            $requestParams = array();
        }
        $requestParams = array(array_merge($authArray, $requestParams));
        cplog($requestParams, '请求参数'); // TODO: Chrome Log

        $response = $client->call('participant.enumerate', $requestParams);
        cplog($response, '响应结果'); // TODO: Chrome Log

        if (isset($response['participants']) || isset($response['status']) && STATUS_SUCCESS == $response['status']) {
            $success = true;
            $participants = $response['participants'];

            return compact("success", "participants");
        }
    } catch (fXmlRpc\Exception\FaultException $fex) {
        cplog($fex, '接口请求异常'); // TODO: Chrome Log
        $msg = $fex->getFaultCode() . ' ' . $fex->getFaultString();
    } catch (Exception $ex) {
        cplog($ex, '未知异常'); // TODO: Chrome Log
        $msg = $ex->getCode() . ' ' . $ex->getMessage();
    }

    return compact("success", "msg");
}


/**
 * 添加与会者
 * @param string $confName        会议名称
 * @param string $participantName 与会者名称
 * @param string $address         与会者账号或者地址
 * @param string $protocol        与会者使用协议, h323 , sip or vnc
 * @return bool
 */
function addParticipant($confName, $participantName, $address, $protocol) {
    global $authArray;
    global $client;

    // 默认结果
    $success = false;
    $msg = "未取到有效数据";

    try {
        $requestParams = array(
            'conferenceName'      => $confName,
            'participantName'     => $participantName,
            'address'             => $address,
            'participantProtocol' => $protocol
        );
        $requestParams = array(array_merge($authArray, $requestParams));
        cplog($requestParams, '请求参数'); // TODO: Chrome Log

        $response = $client->call('participant.add', $requestParams);
        cplog($response, '响应结果'); // TODO: Chrome Log

        if ($response['status'] && STATUS_SUCCESS == $response['status']) {
            $success = true;
            $msg = $response;
        }
    } catch (fXmlRpc\Exception\FaultException $fex) {
        cplog($fex, '接口请求异常'); // TODO: Chrome Log
        $msg = strpos($fex->getFaultString(), 'not supported') >=0 ? '用户使用协议或者客户端不被支持' : ($fex->getFaultCode() . ' ' . $fex->getFaultString());
    } catch (Exception $ex) {
        cplog($ex, '未知异常'); // TODO: Chrome Log
        $msg = $ex->getCode() . ' ' . $ex->getMessage();
    }

    return compact("success", "msg");
}

/**
 * 删除与会者
 * @param  string $confName            会议名称
 * @param  string $participantName     与会者名称(ID)
 * @param  string $participantProtocol 与会者使用协议, h323 , sip or vnc
 * @param  string $participantType     与会者类型，by_address, by_name, or ad_hoc, 默认ad_hoc
 * @return bool 成功返回true, 失败返回false或者错误信息
 */
function disconnectParticipant($confName, $participantName, $participantProtocol, $participantType = 'ad_hoc') {
    global $authArray;
    global $client;

    // 默认结果
    $success = false;
    $msg = "未取到有效数据";

    try {
        $requestParams = array(
            'conferenceName'      => $confName,
            'participantName'     => $participantName,
            'participantProtocol' => $participantProtocol,
            'participantType'     => $participantType
        );
        $requestParams = array(array_merge($authArray, $requestParams));
        cplog($requestParams, '请求参数'); // TODO: Chrome Log

        $response = $client->call('participant.disconnect', $requestParams);
        cplog($response, '响应结果'); // TODO: Chrome Log

        if ($response['status'] && STATUS_SUCCESS == $response['status']) {
            $success = true;
            $msg = $response;
        }
    } catch (fXmlRpc\Exception\FaultException $fex) {
        cplog($fex, '接口请求异常'); // TODO: Chrome Log
        $msg = $fex->getFaultCode() . ' ' . $fex->getFaultString();
    } catch (Exception $ex) {
        cplog($ex, '未知异常'); // TODO: Chrome Log
        $msg = $ex->getCode() . ' ' . $ex->getMessage();
    }

    return compact("success", "msg");
}

/**
 * 给与会者发送信息
 * @param  string $confName            会议名称
 * @param  string $participantName     与会者名称(ID)
 * @param  string $message             信息内容
 * @param  string $participantProtocol 与会者使用协议
 * @param  string $participantType     与会者类型，默认ad_hoc
 * @return bool 成功返回true, 失败返回false或者错误信息
 */
function messageParticipant($confName, $participantName, $message, $participantProtocol, $participantType = 'ad_hoc') {
    global $authArray;
    global $client;

    // 默认结果
    $success = false;
    $msg = "未取到有效数据";

    try {
        $requestParams = array(
            'conferenceName'      => $confName,
            'participantName'     => $participantName,
            'message'             => $message,
            'participantProtocol' => $participantProtocol,
            'participantType'     => $participantType
        );
        $requestParams = array(array_merge($authArray, $requestParams));
        cplog($requestParams, '请求参数'); // TODO: Chrome Log

        $response = $client->call('participant.message', $requestParams);
        cplog($response, '响应结果'); // TODO: Chrome Log

        if ($response['status'] && STATUS_SUCCESS == $response['status']) {
            $success = true;
            $msg = $response;
        }
    } catch (fXmlRpc\Exception\FaultException $fex) {
        cplog($fex, '接口请求异常'); // TODO: Chrome Log
        $msg = $fex->getFaultCode() . ' ' . $fex->getFaultString();
    } catch (Exception $ex) {
        cplog($ex, '未知异常'); // TODO: Chrome Log
        $msg = $ex->getCode() . ' ' . $ex->getMessage();
    }

    return compact("success", "msg");
}

/**
 * 关闭或打开与会者音频
 * @param  string $confName            会议名称
 * @param  string $participantName     与会者名称(ID)
 * @param  bool   $audioRxMuted        是否静音
 * @param  string $participantProtocol 与会者使用协议
 * @param  string $participantType     与会者类型，默认ad_hoc
 * @return bool 成功返回true, 失败返回false或者错误信息
 */
function muteParticipantAudio($confName, $participantName, $audioRxMuted, $participantProtocol, $participantType = 'ad_hoc') {
    global $authArray;
    global $client;

    // 默认结果
    $success = false;
    $msg = "未取到有效数据";

    try {
        $requestParams = array(
            'conferenceName'      => $confName,
            'participantName'     => $participantName,
            'audioRxMuted'        => $audioRxMuted?true:false,
            'participantProtocol' => $participantProtocol,
            'participantType'     => $participantType
        );
        $requestParams = array(array_merge($authArray, $requestParams));
        cplog($requestParams, '请求参数'); // TODO: Chrome Log

        $response = $client->call('participant.modify', $requestParams);
        cplog($response, '响应结果'); // TODO: Chrome Log

        if ($response['status'] && STATUS_SUCCESS == $response['status']) {
            $success = true;
            $msg = $response;
        }
    } catch (fXmlRpc\Exception\FaultException $fex) {
        cplog($fex, '接口请求异常'); // TODO: Chrome Log
        $msg = $fex->getFaultCode() . ' ' . $fex->getFaultString();
    } catch (Exception $ex) {
        cplog($ex, '未知异常'); // TODO: Chrome Log
        $msg = $ex->getCode() . ' ' . $ex->getMessage();
    }

    return compact("success", "msg");
}

/**
 * 关闭或打开与会者视频
 * @param  string $confName            会议名称
 * @param  string $participantName     与会者名称(ID)
 * @param  bool   $videoRxMuted        是否静音
 * @param  string $participantProtocol 与会者使用协议
 * @param  string $participantType     与会者类型，默认ad_hoc
 * @return bool 成功返回true, 失败返回false或者错误信息
 */
function muteParticipantVideo($confName, $participantName, $videoRxMuted, $participantProtocol, $participantType = 'ad_hoc') {
    global $authArray;
    global $client;

    // 默认结果
    $success = false;
    $msg = "未取到有效数据";

    try {
        $requestParams = array(
            'conferenceName'      => $confName,
            'participantName'     => $participantName,
            'videoRxMuted'        => $videoRxMuted?true:false,
            'participantProtocol' => $participantProtocol,
            'participantType'     => $participantType
        );
        $requestParams = array(array_merge($authArray, $requestParams));
        cplog($requestParams, '请求参数'); // TODO: Chrome Log

        $response = $client->call('participant.modify', $requestParams);
        cplog($response, '响应结果'); // TODO: Chrome Log

        if ($response['status'] && STATUS_SUCCESS == $response['status']) {
            $success = true;
            $msg = $response;
        }
    } catch (fXmlRpc\Exception\FaultException $fex) {
        cplog($fex, '接口请求异常'); // TODO: Chrome Log
        $msg = $fex->getFaultCode() . ' ' . $fex->getFaultString();
    } catch (Exception $ex) {
        cplog($ex, '未知异常'); // TODO: Chrome Log
        $msg = $ex->getCode() . ' ' . $ex->getMessage();
    }

    cplog($requestParams, '请求参数');

    return compact("success", "msg");
}


/**
 * 修改与会者名称
 * @param  string $confName                  会议名称
 * @param  string $participantName           与会者名称(ID)
 * @param  bool   $displayNameOverrideStatus 是否修改显示名称
 * @param  string $displayNameOverrideValue  与会者显示名称
 * @param  string $participantProtocol       与会者使用协议
 * @param  string $participantType           与会者类型，默认ad_hoc
 * @return bool 成功返回true, 失败返回false或者错误信息
 */
function modifyParticipantDisplayName($confName, $participantName, $displayNameOverrideStatus, $displayNameOverrideValue, $participantProtocol, $participantType = 'ad_hoc') {
    global $authArray;
    global $client;

    // 默认结果
    $success = false;
    $msg = "未取到有效数据";

    try {
        $requestParams = array(
            'conferenceName'            => $confName,
            'participantName'           => $participantName,
            'participantProtocol'       => $participantProtocol,
            'participantType'           => $participantType,
            'displayNameOverrideStatus' => $displayNameOverrideStatus?true:false,
            'displayNameOverrideValue'  => $displayNameOverrideValue
        );
        $requestParams = array(array_merge($authArray, $requestParams));
        cplog($requestParams, '请求参数'); // TODO: Chrome Log

        $response = $client->call('participant.modify', $requestParams);
        cplog($response, '响应结果'); // TODO: Chrome Log

        if ($response['status'] && STATUS_SUCCESS == $response['status']) {
            $success = true;
            $msg = $response;
        }
    } catch (fXmlRpc\Exception\FaultException $fex) {
        cplog($fex, '接口请求异常'); // TODO: Chrome Log
        $msg = $fex->getFaultCode() . ' ' . $fex->getFaultString();
    } catch (Exception $ex) {
        cplog($ex, '未知异常'); // TODO: Chrome Log
        $msg = $ex->getCode() . ' ' . $ex->getMessage();
    }

    return compact("success", "msg");
}

// 错误信息列表
$faultExceptionArray = array(
    1   => 'method not supported.', //  This method is not supported on this device.
    2   => 'duplicate conference name.', //  A conference name was specified, but is already in use.
    3   => 'duplicate participant name.', //  A participant name was specified, but is already in use.
    4   => 'no such conference or auto attendant.', //  The conference or auto attendant identification given does not match any conference or auto attendant.
    5   => 'no such participant.', //  The participant identification given does not match any participants.
    6   => 'too many conferences.', //  The device has reached the limit of the number of conferences that can be configured.
    7   => 'too many participants.', //  There are already too many participants configured and no more can be created.
    8   => 'no conference name or auto attendant id supplied.', //  A conference name or auto attendant identifier was required, but was not present.
    9   => 'no participant name supplied.', //  A participant name is required but was not present.
    10  => 'no participant address supplied.', //  A participant address is required but was not present.
    11  => 'invalid start time specified.', //  A conference start time is not valid.
    12  => 'invalid end time specified.', //  A conference end time is not valid.
    13  => 'invalid PIN specified.', //  A PIN specified is not a valid series of digits.
    14  => 'authorization failed.', //  The requested operation is not permitted on this device.
    15  => 'insufficient privileges.', //  The specified user id and password combination is not valid for the attempted operation.
    16  => 'invalid enumerateID value.', //  An enumerate ID passed to an enumerate method invocation was invalid. Only values returned by the device should be used in enumerate methods.
    17  => 'port reservation failure.', //  This is in the case that reservedAudioPorts or reservedVideoPorts value is set too high, and the device cannot support this.
    18  => 'duplicate numeric ID.', //  A numeric ID was given, but this ID is already in use.
    19  => 'unsupported protocol.', //  A protocol was used which does not correspond to any valid protocol for this method. In particular, this is used for participant identification where an invalid protocol is specified.
    20  => 'unsupported participant type.', //  A participant type was used which does not correspond to any participant type known to the device.
    21  => 'no conference alias supplied.', //  A conference alias is required but was not present.
    22  => 'conference.', // modify 'locked' param unsupported. The conference.modify parameter 'locked' is not supported in the TelePresence Conductor API.
    25  => 'new port limit lower than currently active.', //
    26  => 'floor control not enabled for this conference.', //
    27  => 'no such template.', //  The specified template wasn't found.
    30  => 'unsupported bit rate.', //  A call tried to set a bit rate that the device does not support.
    31  => 'template name in use.', //  This occurs when trying to create or rename a template to have the same name as an existing template.
    32  => 'too many templates.', //  This occurs when trying to create a new template after the limit of 100 templates has been reached.
    36  => 'required value missing.', //  The call has omitted a value that the TelePresence MCU requires to make the change requested by the call.
    42  => 'port conflict.', //  The call attempts to set a port number that is already in use by another service.
    43  => 'route already exists.', //  The call attempts to add a route that has the same destination and prefixLength as a route that already exists on the TelePresence Conductor.
    44  => 'route rejected.', //  The call attempts to add a route to a forbidden subnet
    45  => 'too many routes.', //  The call can not add the route because doing so would exceed the allowed number of routes.
    46  => 'no such route.', //  The TelePresence Conductor has no record of a route that has the provided routeId.
    48  => 'IP address overflows prefix length.', //  The call attempts to make a route destination more specific than the range defined by the prefixLength.
    49  => 'operation would disable active interface.', //
    101 => 'missing parameter.', //  This is given when a required parameter is absent. The parameter in question is given in the fault string in the format "missing parameter - parameter_name".
    102 => 'invalid parameter.', //  This is given when a parameter was successfully parsed, is of the correct type, but falls outside the valid values; for example an integer is too high or a string value for a protocol contains an invalid protocol. The parameter in question is given in the fault string in the format "invalid parameter - parameter_name".
    103 => 'malformed parameter.', //  This is given when a parameter of the correct name is present, but cannot be read for some reason; for example the parameter is supposed to be an integer, but is given as a string. The parameter in question is given in the fault string in the format "malformed parameter - parameter_ name".
    104 => 'mismatched parameters.', //  The call provides related parameters that, when considered together, are not expected/supported.
    201 => 'operation failed.', //  This is a generic fault for when an operation does not succeed as required.
);

/*****************************************************
 * 工具函数
 *****************************************************/

/**
 *
 * @return [type] [description]
 */
/**
 * 打印变量
 * @param  string $var   变量
 * @param  string $label 变量说明
 * @return void
 */
function dump($var, $label = '') {
    if ($label)
        print("<h5>".$label."</h5>");

    print('<pre>');
    print_r($var);
    print('</pre>');
}

/**
 * 使用 ChromePhp 调试变量
 * @param  string $var   变量
 * @param  string $label 变量说明
 * @return void
 */
function cplog($var, $label = '') {
    if ($label)
        \ChromePhp::log($label);

    \ChromePhp::log($var);
}

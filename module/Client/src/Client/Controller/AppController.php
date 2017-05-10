<?php
namespace Client\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Client\Service\ZpkInvokable;

/**
 * App Console Controller
 *
 * High-Level Application Deployment CLI commands
 */
class AppController extends AbstractActionController
{
    public function installAction()
    {
        $requestParameters = array();
        $zpk     = $this->params('zpk');
        $baseUri = $this->params('baseUri');
        $userParams = $this->params('userParams', array());
        $appName    = $this->params('userAppName');
        $appId      = 0;
        $appStatus  = null;
        $wait       = $this->params('wait');
        $safe       = $this->params('safe');

        $apiManager = $this->serviceLocator->get('zend_server_api');
        $zpkService = $this->serviceLocator->get('zpk');
        try {
            $xml = $zpkService->getMeta($zpk);
        } catch (\ErrorException $ex) {
            throw new \Zend\Mvc\Exception\RuntimeException($ex->getMessage(), $ex->getCode(), $ex);
        }

        if (isset($xml->type) && $xml->type == ZpkInvokable::TYPE_LIBRARY) {
            return $this->forward()->dispatch('webapi-lib-controller');
        }

        // validate the package
        $zpkService->validateMeta($zpk);

        if (!$appName) {
            // get the name of the application from the package
            $appName = sprintf("%s", $xml->name);
            // or the baseUri
            if (!$appName) {
                $appName = str_replace($baseUri, '/', '');
            }
        }

        // check what applications are deployed
        $response = $apiManager->applicationGetStatus();
        foreach ($response->responseData->applicationsList->applicationInfo as $appElement) {
            if ($baseUri ? $appElement->baseUrl == $baseUri : $appElement->userAppName == $appName) {
                $appId = $appElement->id;
                $appStatus = sprintf("%s", $appElement->status);
                break;
            }
        }

        if (!$appId) {
            $params = array(
                'action'      => 'applicationDeploy',
                'appPackage'  => $zpk,
                'baseUrl'     => $baseUri,
                'userAppName' => $appName,
                'userParams'  => $userParams,
            );

            $optionalParams = array('createVhost', 'defaultServer', 'ignoreFailures');
            foreach ($optionalParams as $key) {
                $value = $this->params($key);
                if ($value) {
                    $params[$key] = $value;
                }
            }
            $response = $this->forward()->dispatch('webapi-api-controller', $params);
            if ($wait) {
                $xml = new \SimpleXMLElement($response->getBody());
                $appId = $xml->responseData->applicationInfo->id;
                $response = $this->repeater()->doUntil(array($this, 'onWaitInstall'),
                                                       array(
                                                          'appId'=>sprintf("%s", $appId)
                                                       ));
            }
            
            return $response;
        }
        
        if ($safe && !$wait && $this->isAppDeploymentRunning($appStatus)) {
            throw new \Zend\Mvc\Exception\RuntimeException(
                "Previous version is still being deployed. Use the --wait flag if you want to wait"
                );
        }
        
        $updateFunc = function ($wait) use ($appId, $zpk, $userParams) {
            // update the application
            $response = $this->forward()->dispatch('webapi-api-controller', array(
                'action'     => 'applicationUpdate',
                'appId'      => $appId,
                'appPackage' => $zpk,
                'userParams' => $userParams,
            ));
            
            if ($wait) {
                $response = $this->repeater()->doUntil(array($this, 'onWaitInstall'),
                    array(
                        'appId'=>sprintf("%s", $appId),
                    ));
            }
            
            return $response;
        };
        
        if (!$safe || ($safe && !$this->isAppDeploymentRunning($appStatus))) {
            return $updateFunc($wait);
        }
        
        // if safe and wait
        return $this->repeater()->doUntil(function () use ($apiManager, $updateFunc, $appId, $wait) {
            $response = $apiManager->applicationGetStatus(array('applications'=> $appId));
            foreach ($response->responseData->applicationsList->applicationInfo as $appElement) {
                if (sprintf("%s", $appElement->id) == $appId) {
                    $appStatus = sprintf("%s", $appElement->status);
                    break;
                }
            }
            
            if (!$this->isAppDeploymentRunning($appStatus)) {
                return $updateFunc($wait);
            }
        });
    }

    public function isAppDeploymentRunning($appStatus)
    {
        return !(
            in_array($appStatus, array("error", "deployed", "notExists")) ||
            preg_match('/(\w+)Error$/', $appStatus)
        );
    }
    
    /**
     * Returns response if the action finished as expected
     * @param AbstractActionController $controller
     * @param array $params
     */
    public function onWaitInstall($controller, $params)
    {
        $appId = $params['appId'];
        $response = $controller->forward()->dispatch('webapi-api-controller', array(
                    'action'     => 'applicationGetStatus',
                    'applications'  => array($appId)
        ));
        $xml = new \SimpleXMLElement($response->getBody());

        $status = (string)$xml->responseData->applicationsList->applicationInfo->status;
        if (stripos($status, 'error')!==false) {
            throw new \Exception(sprintf("Got error '%s' during deployment.\nThe following error message is reported from the server:\n%s", $status, $xml->responseData->applicationsList->applicationInfo->messageList->error));
        }

        if ($status !='deployed') {
            return;
        }

        return $response;
    }
}

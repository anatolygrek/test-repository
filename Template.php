<?php
/**
* @category Avk
* @package SendWithUs_Mail
* @author Koval Anatoly
**/

require_once(Mage::getBaseDir('lib') . '/Sendwithus/API.php');

class Sendwithus_Mail_Model_Template extends Mage_Core_Model_Email_Template
{
    
    private function mageObjToArray($obj)
    {
        $result = array();
        $props = get_class_methods($obj);

        foreach ($props as $prop) {
            try {
                if (substr($prop, 0, 3) == 'get' || substr($prop, 0, 2) == 'if') {
                    $result[$prop] = $obj->$prop();
                }
            } catch (Exception $e) {
                // pass
            }
        }

        return $result;
    }

    private function mageSerialize($obj)
    {
        $result = array();

        if (is_object($obj) && (is_subclass_of($obj,"Varien_Object") || get_class($obj) == "Varien_Object")) {
            $result = array_replace($result, $obj->getData());
        } else {
			if(is_object($obj))
            	$result = $this->mageObjToArray($obj);
            else
            	$result = $obj;
            //$result = $obj->getData();
            Mage::log('done processing obj', null, 'mail.log');
        }
        //} else (get_parent_class($obj) == "Mage_Core_Model_Abstract") {
        if(is_array($result)){	
        	// check, if some of children are not simple values
	        foreach ($result as $k=>$val){
    	    	if(is_object($val)) $result[$k] = $this->mageSerialize($val);
        	}
        }
        return $result;
    }

    /**
     * Send transactional email to recipient
     *
     * @param   int $templateId
     * @param   string|array $sender sneder informatio, can be declared as part of config path
     * @param   string $email recipient email
     * @param   string $name recipient name
     * @param   array $vars varianles which can be used in template
     * @param   int|null $storeId
     * @return  Mage_Core_Model_Email_Template
     */

    public function sendTransactional($templateId, $sender, $email, $name, $vars=array(), $storeId=null)
    {
        $this->setSentSuccess(false);

        if (($storeId === null) && $this->getDesignConfig()->getStore()) {
            $storeId = $this->getDesignConfig()->getStore();
        }

        $store = Mage::app()->getStore();

		// check, if this email should be processed by sendwithus_mail
		$collection = Mage::getSingleton('mail/emails')->getCollection();

		//filter for email_code (templateId), checked, selected available email
		$collection->addFieldToFilter('email_code', $templateId);		
		$collection->addFieldToFilter('checked', 1);		
		$collection->addFieldToFilter('available_id', array("notnull" => true));		

		if(count($collection) > 0) {	// found system email to process
            $mail = $collection->getFirstItem();
            $availableMail = Mage::getSingleton('mail/available')->load($mail->getAvailable_id());

            if (!is_array($sender)) {
                $sender = array(
                    'name' => Mage::getStoreConfig('trans_email/ident_' . $sender . '/name', $storeId),
                    'address' => Mage::getStoreConfig('trans_email/ident_' . $sender . '/email', $storeId)
                );
            } else {
                $sender = array(
                    'name' => $sender['name'],
                    'address' => $sender['email']
                );
            }

            // @todo get API KEY HERE
            $sendwithus_api_key = '31ac941d907f141d607061e4ca6d5b6d95bd33e8';

            // setup the sendwithus api
            $api = new \sendwithus\API($sendwithus_api_key, 
                array('DEBUG'=> true,
            ));

            // $email MAY be an array
            $emails = array_values((array)$email);
            $names = is_array($name) ? $name : (array)$name;
            $names = array_values($names);

            foreach ($emails as $key => $address) {
                if (!isset($names[$key])) {
                    $names[$key] = substr($address, 0, strpos($address, '@'));
                }

                $recipient = array(
                    'address' => $address,
                    'name' => $names[$key]
                );

                Mage::log('Sending email:' . $templateId . ' to ' . $recipient['address'], null, 'mail.log');

                $email_data = array();

                foreach ($vars as $k=>$var) {
					if(substr($k, 0, 1)=="_") continue;	// the fields, beginning from _ are not for use by external app
                	$email_data[$k] = $this->mageSerialize($var);
                }

                $email_data['store'] = $this->mageSerialize($store);

                Mage::log(print_r($email_data, true), null, 'mail.log');

                $response = $api->send($availableMail->getEmail_id(), 
                    $recipient,
                    $email_data,
                    $sender
                );
            }

            $this->setSentSuccess(true);
	        return $this; // return
		}

		// continue default transactional processing
		parent::sendTransactional($templateId, $sender, $email, $name, $vars, $storeId);
        return $this;
    }

}

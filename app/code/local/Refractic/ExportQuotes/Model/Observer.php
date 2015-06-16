<?php
class Refractic_ExportQuotes_Model_Observer
{
		
	//INTEGRATES CART2QUOTE WITH SALESLOGIX API
	//cron checks for new quotes periodically and runs below 
	//search for 'CUSOMIZE THIS' to set all the custom SLX IDs, names, emails and API Username/password. 

	function getOwner($brand, $state='Queensland', $country='AU') {

		// CUSTOMIZE THIS FUNCTION
		//this function needs to return one SLX ID that is the 'owner' or the division the quote is held under. 
		//you can use brand, state or country to allocate the quote to the right department
		return 'ABCDEF123456';

	}

	function getAccountManager($owner,$state,$brand,$country){

		// CUSTOMIZE THIS FUNCTION WITH YOUR OWN SLX USER IDS, NAMES AND EMAILS.
		//this function should return an array as below 
		//use custom logic to route quote to the right salesperson. 
		//you can use geographic country/state, brand, or division (owner)
		//for example... 

		if($owner=='ABCDEF123456') return array('id'=>'ABCDEF123456','email'=>'someone@somwhere.com','name'=>'First Last');
		elseif($brand=='whatever') return array('id'=>'ABCDEF123456','email'=>'someone@somwhere.com','name'=>'First Last');
		elseif($country!='US') return array('id'=>'ABCDEF123456','email'=>'someone@somwhere.com','name'=>'First Last');
		else return array('id'=>'ABCDEF123456','email'=>'someone@somwhere.com','name'=>'First Last');


	}

	public function exportNew() {
		
		//CUSTOMIZE THIS.. 
		//email that is sent to the account manager... 
		//where to send the email from... 
		$sender = array('name' => 'Company',
				'email' => 'name@email.com');

		//wanna bcc anyone? 
		//$bcc = array('email@email.com','email2@email.com');			

		//edit this template to change email that gets sent to account manager
		$templateEmail = 1;                        

		//============== END CUSTOMIZATION =================//

		$model = Mage::getModel('qquoteadv/api');

		//we set imported to 1 and status to 50 on success and 60 on failure so we won't keep trying the same one over and over
		$quotes = $model->items( array("imported"=>"0","status"=>"20") );

		//echo "<pre>"; print_r($quotes); //die; 

		foreach ($quotes['items'] as $key => $quote)
		{

			//CLEAR LOOP
			unset($info,$owner,$products,$item,$accountManager,$contactId,$accountId,$user,$input,
				$address,$addressId,$contact,$account,$emailExist,$DoNotSolicit,$opportunity,$opportunityId,$product_table,
				$notfound_table,$notfound,$assignment,$table,$date,$mod,$QuoteValidTill,$QuoteDate,$slxIDs,$RecipientEmail,
				$RecipientName,$translate,$template,$history,$futureDate,$activity,$theQuote);
			
			$quote['firstname'] = ucfirst($quote['firstname']);
			$quote['lastname'] = ucfirst($quote['lastname']);
			
			$info = $model->info( $quote['quote_id'] );
					
			Mage::log("Working on ".$quote['increment_id'],null,'slx.log');
			
			//we need to load the quote model so that we can reset the status to proposal or denied (on error)
			$theQuote = Mage::getModel('qquoteadv/qqadvcustomer')->load($quote['quote_id']);
						
			$owner = array(); $brands=array(); $products=array();
			
			//we have to go through the products in the quote first so we know how to set the owner and acc. manager
			//get products into the opportunity
			foreach($info['items'] as $item) //[0]['data']['items']
			{
			
				$_product = Mage::getModel('catalog/product')->setStoreId(0)->load($item['product_id']);
				//get the sku from the quote product id
				$products[] = array(
					'sku' => $_product->getSku(),
					'brand' => $brand = Mage::getModel('catalog/product')->setStoreId(0)->load($item['product_id'])->getResource()->getAttribute('Manufacturer')->getFrontend()->getValue( Mage::getModel('catalog/product')->setStoreId(0)->load($item['product_id']) ),
					'owner' => $this->getOwner($brand),
					'price' => $item['data']['items'][0]['original_price'],
					'qty' => $item['data']['items'][0]['request_qty'],
					'notes' => $item['client_request'],
					'name' => $_product->getName(),
					'url' => $_product->getProductUrl()
						);
				
				//get the owner for each brand of product
				$owner[] = (string) $this->getOwner($brand,$quote['region'],$quote['country_id']);
				$brands[] = (string) $brand;	
				unset($_product,$brand);
			}
			
			//count the owners and find which one has the most product associated
			$owner = array_count_values ( $owner );
			arsort( $owner  );
			$owner = (string) current( array_keys( $owner ) ); 
			
			//count the brands and find which one has the most product associated
			$brands = array_count_values ( $brands );
			asort( $brands );
			$brand = (string) current( array_keys( $brands ) );
			unset($brands);
			
			Mage::log("Owner is: ".$owner."<br>\n",null,'slx.log');
			Mage::log("Main brand is: ".$brand."<br>\n",null,'slx.log');
			
			//get the account manager. This returns an array with AM code and ppl to email notifictions to........................... 
			$accountManager = $this->getAccountManager($owner,$quote['region'],$brand,$quote['country_id']); 
			
			Mage::log("Account manager is: ".$accountManager['id']."<br>\n",null,'slx.log');
			
			Mage::log("Customer email is: ".$quote['email']."<br>\n",null,'slx.log');
			
			//does the contact exist? lets try find them by email. if they're there
			//it returns an array otherwise it returns false         
			if( $contact = $this->transact2sdata("GET","contacts","?where=Email eq '".$quote['email']."'",NULL,TRUE) )
			{
				
				
				Mage::log("Contact exists based on search by email<br>\n",null,'slx.log');
				
				$contactId = $contact['slx:Contact_attr']['sdata:key'];
				Mage::log("Contact ID returned by search is: ".$contactId."<br>\n",null,'slx.log');
				if(!$contactId) {Mage::log('ERROR Thought we\'d found contact by email address but couldn\'t grab the id in the end for '.$quote['increment_id'].'. Here is the response: '.print_r($contact,true), null, 'slx.log'); 
								$theQuote->setStatus('60')->save(); continue;}
				
				$accountId = $contact['slx:Contact']['slx:Account_attr']['sdata:key'];
				Mage::log("Account ID from preexisting contact is: ".$accountId."<br>\n",null,'slx.log');
				if(!$accountId) {Mage::log('ERROR Supposedly found contactId (using email address) but unable to obtain accountId for '.$quote['increment_id'],null,'slx.log'); 
								$theQuote->setStatus('60')->save(); continue;}
				
				//the contacts AM & owner override the products driven allocation 
				$owner = $contact['slx:Contact']['slx:Owner_attr']['sdata:key'];
				Mage::log("Potentially resetting owner id based on what preexisting contact owner was: ".$owner."<br>\n",null,'slx.log');
				$accountManager['id'] = $contact['slx:Contact']['slx:AccountManager_attr']['sdata:key'];
				Mage::log("Potentially resetting account manager id based on what the preexisting contact account manager was: ".$accountManager['id']."<br>\n",null,'slx.log');
				
				//get the AM's name and email
				$user = $this->transact2sdata("GET","userInfo","('".$accountManager['id']."')",NULL,FALSE);
				$accountManager['email'] = $user['response']['entry']['sdata:payload']['slx:UserInfo']['slx:Email'];
				$accountManager['name'] = $user['response']['entry']['sdata:payload']['slx:UserInfo']['slx:UserName'];
				$accountManager['department'] = $user['response']['entry']['sdata:payload']['slx:UserInfo']['slx:Department'];
				
				//maybe we should update the contact info here. Just creating a new address
				$input = array('Address' => array(
					'Address1' => ucfirst( substr( $quote['address'], 0, 63 ) ),
					'City' => strtoupper($quote['city']),
					'State' => $this->convState($quote['region']),
					'PostalCode' => $quote['postcode'],
					'Country' => $this->convCountry($quote['country_id']),
					'Description' => 'Office',
					'IsPrimary' => 'true',
					'IsMailing' => 'true',
					'EntityId' => $accountId
				));

				$address = $this->transact2sdata("PUT","addresses",NULL,$input,TRUE); 
				$addressId = $address['slx:Address_attr']['sdata:key'];
				
				$input = array('Contact' => array(
					'AccountName' => $quote['company'],
					'FirstName' => $quote['firstname'],
					'LastName' => $quote['lastname'],
					'Email' => $quote['email'],
					'Fax' => $quote['fax'],
					'WorkPhone' => $quote['telephone'],
					'IsPrimary' => 'true',
		//			'Account' => array('@attributes'=>array('sdata:key'=>$accountId),
		//								'value'=>NULL),
					'Address' => array('@attributes'=>array('sdata:key'=>$addressId), 
										'value'=>NULL)
		//			'Owner' => array('@attributes'=>array('sdata:key'=>$owner),
		//								'value'=>NULL),
		//			'AccountManager' => array('@attributes'=>array('sdata:key'=>$accountManager['id']),
		//								'value'=>NULL)
				));
				//left some stuff out of above because it's just an update
						
				$contact = $this->transact2sdata("PUT","contacts","('".$contactId."')",$input,TRUE);
				
			}
			//the above returned false - lets create
			else {
				
				//the contact did not exist but does the account exist? 
				if( $account = $this->transact2sdata("GET","accounts","?where=AccountName eq '".$quote['company']."'",NULL,TRUE) )
				{
					//echo "<pre>"; print_r($contact); 
					Mage::log("Contact not found but account found <br>\n",null,'slx.log');

					$accountId = $account['slx:Account_attr']['sdata:key'];
					Mage::log("Account ID returned by search against company name is: ".$accountId."<br>\n",null,'slx.log');
					if(!$accountId) {Mage::log('ERROR Thought we\'d found account by account name ('.$quote['company'].') but was unable to grab the id in the end for '.$quote['increment_id'],null,'slx.log'); $theQuote->setStatus('60')->save(); continue;}
				}else{
					//create the account
					$input = array('Account' => array(
						'AccountName' => $quote['company'],
						'SubType' => 'Unknown',
						'Industry' => 'Unknown',
						'LeadSource' => 'WebRFQ',
						'Owner' => array('@attributes'=>array('sdata:key'=>$owner), 
											'value'=>NULL),
						'AccountManager' => array('@attributes'=>array('sdata:key'=>$accountManager['id']),
											'value'=>NULL)
					));
					
					$account = $this->transact2sdata("POST","accounts",NULL,$input,TRUE); 
					$accountId = $account['slx:Account_attr']['sdata:key'];
					Mage::log("Account ID created is: ".$accountId."<br>\n",null,'slx.log');
					if(!$accountId) {Mage::log('ERROR Account not created for '.$quote['increment_id'].' Request: '.$this->array2xml($input).' Response: '.print_r($account,true) ,null,'slx.log'); 				$theQuote->setStatus('60')->save(); continue;}
				}
				
				//create the address, all you really need is entityid and description
				$input = array('Address' => array(
					'Address1' => ucfirst( substr( $quote['address'], 0, 64 ) ),
					'City' => strtoupper($quote['city']),
					'State' => $this->convState($quote['region']),
					'PostalCode' => $quote['postcode'],
					'Country' => $this->convCountry($quote['country_id']),
					'Description' => 'Office',
					'IsPrimary' => 'true',
					'IsMailing' => 'true',
					'EntityId' => $accountId
				));

				$address = $this->transact2sdata("POST","addresses",NULL,$input,TRUE); 
				$addressId = $address['slx:Address_attr']['sdata:key'];
				Mage::log("Address ID created is: ".$addressId."<br>\n",null,'slx.log');
				if(!$addressId) {Mage::log('ERROR Address not created for '.$quote['increment_id'].' Request: '.$this->array2xml($input).' Response: '.print_r($address,true) ,null,'slx.log'); 
								$theQuote->setStatus('60')->save(); continue;}
			
				//did they subscribe to newsletter?? 
				$emailExist = Mage::getModel('newsletter/subscriber')->load($quote['email'], 'subscriber_email');
				if ($emailExist->getId()) {
					$DoNotSolicit = 'false';
				}{
					$DoNotSolicit = 'true';
				}
			
				//this is the array to create a contact
				$input = array('Contact' => array(
					'AccountName' => $quote['company'],
					'FirstName' => $quote['firstname'],
					'LastName' => $quote['lastname'],
					'Email' => $quote['email'],
					'DoNotSolicit' => $DoNotSolicit,
					'Fax' => $quote['fax'],
					'WorkPhone' => $quote['telephone'],
					'IsPrimary' => 'true',
					'Account' => array('@attributes'=>array('sdata:key'=>$accountId),
										'value'=>NULL),
					'Address' => array('@attributes'=>array('sdata:key'=>$addressId), 
										'value'=>NULL),
					'Owner' => array('@attributes'=>array('sdata:key'=>$owner),
										'value'=>NULL),
					'AccountManager' => array('@attributes'=>array('sdata:key'=>$accountManager['id']),
										'value'=>NULL)
				));

						
				$contact = $this->transact2sdata("POST","contacts",NULL,$input,TRUE);
				$contactId = $contact['slx:Contact_attr']['sdata:key'];
				Mage::log("Contact ID created is: ".$contactId."<br>\n",null,'slx.log');
				if(!$contactId) {Mage::log('ERROR Contact not created for '.$quote['increment_id'].' Request: '.$this->array2xml($input).' Response: '.print_r($contact,true) ,null,'slx.log'); 
								$theQuote->setStatus('60')->save(); continue;}
			
			}
			
			//this is the array to create an opportunity
			$input = array('Opportunity' => array(
				'Description' => 'RFQ '.$quote['increment_id'].' from Website',
				'Type' => 'Quote',
				'Notes' => $quote['client_request'],
				'LeadSource' => 'WebRFQ',
				'Account' => array('@attributes'=>array('sdata:key'=>$accountId),
									'value'=>NULL),
				'Owner' => array('@attributes'=>array('sdata:key'=>$owner),
									'value'=>NULL),
				'AccountManager' => array('@attributes'=>array('sdata:key'=>$accountManager['id']),
									'value'=>NULL)
			));

			$opportunity = $this->transact2sdata("POST","opportunities",NULL,$input,TRUE);
			$opportunityId = $opportunity['slx:Opportunity_attr']['sdata:key'];
			Mage::log("Opportunity ID created is: ".$opportunityId."<br>\n",null,'slx.log');
			if(!$opportunityId) {Mage::log('ERROR Opportunity not created for '.$quote['increment_id'].' Request: '.$this->array2xml($input).' Response: '.print_r($opportunity,true) ,null,'slx.log'); 					$theQuote->setStatus('60')->save(); continue;}
			
			$info = $model->info($quote['quote_id']);

			//we start building the html table of products for the email that is sent
			$product_table = "<table><tr><th>Part Number</th><th>Quantity</th><th>Name</th></tr>";
			$notfound_table = "<table><tr><th>Part Number</th><th>Quantity</th><th>Name</th></tr>";
			$notfound = FALSE;
			
			//get products into the opportunity
			foreach($products as $item)
			{
				
				//gather the slx product id for the sku
				$product = $this->transact2sdata("GET","products","?where=Name eq '".$item['sku']."'",NULL,TRUE);
				
				//if the product isn't in slx
				if(!isset($product['slx:Product_attr']['sdata:key'])){
				
					$notfound_table .= "<tr><td>".$item['sku']."</td><td>".$item['qty']."</td><td><a href='".$item['url']."'>".$item['name']."</a></td></tr>";
					$notfound = TRUE;
					Mage::log('ERROR cant find product '.$item['sku'].' in SLX from quote '.$quote['increment_id'],null,'slx.log'); //we don't break for this.. just log
					
				}else{
				
					//this is the array to create products associated with opportunity....
					$input = array('opportunityproduct' => array(
						'Quantity' => $item['qty'],
						'Price' => $item['price'],
						'Notes' => $item['notes'],
						'Opportunity' => array('@attributes'=>array('sdata:key'=>$opportunityId),
											'value'=>NULL),
						'Product' => array('@attributes'=>array('sdata:key'=> $product['slx:Product_attr']['sdata:key'] ),
											'value'=>NULL)
					));

					$product_table .= "<tr><td>".$item['sku']."</td><td>".$item['qty']."</td><td><a href='".$item['url']."'>".$item['name']."</a></td></tr>";
					
					$assignment = $this->transact2sdata("POST","opportunityproducts",NULL,$input,TRUE);
				}
			}
			
			$product_table .= "</table>";
			$notfound_table .= "</table>";
			
			$table = "<h2>The following products were included in the quote request:</h2><br>".$product_table; 
			if ($notfound) $table .= "<h2>The following products were not found in Saleslogix and need to be manually added to the quote:</h2><br> ".$notfound_table; 
			
			//add at least one contact to the opportunity
			$input = array('OpportunityContact' => array(
				'IsPrimary' => 'true',
				'Opportunity' => array('@attributes'=>array('sdata:key'=>$opportunityId),
									'value'=>NULL),
				'Contact' => array('@attributes'=>array('sdata:key'=> $contactId ),
									'value'=>NULL)
			));

			$assignment = $this->transact2sdata("POST","opportunityContacts",NULL,$input,TRUE);
			

			//set some dates... 
			//2013-06-15T00:00:05+00:00
			$date = date("Y-m-d");
			$mod = strtotime($date." +14 days");
			$QuoteValidTill = date("c",$mod);
			$QuoteDate = date("c");
			
			$input = array('OpportunityCustom' => array(
				'QuoteDate' => $QuoteDate,
				'QuoteValidTill' => $QuoteValidTill
		//		'Opportunity' => array('@attributes'=>array('sdata:key'=>$opportunityId),
		//							'value'=>NULL),
		//		'Contact' => array('@attributes'=>array('sdata:key'=> $contactId ),
		//							'value'=>NULL)
			));

			$assignment = $this->transact2sdata("PUT","opportunityCustom","('".$opportunityId."')",$input,TRUE);
			if(!$assignment) {Mage::log('ERROR OpportunityCustom not updated for '.$quote['increment_id'].' Request: '.$this->array2xml($input).' Response: '.print_r($assignment,true) ,null,'slx.log'); }

			//collect all the ids for the email
			$slxIDs = array(
				'account' =>$accountId,
				'contact' => $contactId,
				'opportunity' => $opportunityId,
				'history' => $history['slx:History_attr']['sdata:key']
				);
			
			$RecipientEmail = $accountManager['email'];
			$RecipientName = $accountManager['name'];
						
			//Now email the account manager
			$translate = Mage::getSingleton('core/translate');
			$translate->setTranslateInline(false);            
			
			$template = Mage::getModel('core/email_template');
			$template->setDesignConfig(array('area' => 'frontend', 'store' => 1));
			
			if(isset($bcc))
				$template->addBCC($bcc);
			
			
			$template->sendTransactional(
							$templateEmail,                       
							$sender,
							$RecipientEmail,
							$RecipientName,
							array('quote' => new Varien_Object($quote), 'info' => new Varien_Object($info), 'product_table' => $table, 'slxids' => new Varien_Object($slxIDs) ));

			if (!$template->getSentSuccess()) { Mage::log('ERROR Sending email notification for '.$quote['increment_id'] ,null,'slx.log'); }				
			else { Mage::log( "Email sent to: ".$RecipientEmail , null , 'slx.log' ); }
			
			$translate->setTranslateInline(true);
			
			//save a copy of the email in sales logix history
			$template = Mage::getModel('core/email_template')
			->load($templateEmail)
			->getProcessedTemplate(array('quote' => new Varien_Object($quote), 'info' => new Varien_Object($info), 'product_table' => $table ));
			
			$template = Mage::getModel('exportquotes/html2text')->convert_html_to_text($template); 
			
			$input = array('History' => array(
				'Description' => 'Email sent to '.$accountManager['name'].' about web quote request '.$quote['increment_id'],
				'LongNotes' => $template,
				'ContactId' => $contactId,
				'AccountId' => $accountId,
				'OpportunityId' => $opportunityId,
				'ContactName' => $quote['firstname'].' '.$quote['lastname'],
				'UserId' => $accountManager['id'],
				'Type' => 'atNote'
			));

			$history = $this->transact2sdata("POST","history",NULL,$input,TRUE);
			Mage::log("History ID created is: ".$history['slx:History_attr']['sdata:key']."<br>\n",null,'slx.log');
			if(!$history) {Mage::log('ERROR History not created for '.$quote['increment_id'].' Request: '.$this->array2xml($input).' Response: '.print_r($history,true) ,null,'slx.log'); }
			
			//2013-06-15T00:00:05+00:00
			$date = date("Y-m-d");
			$mod = strtotime($date." +2 weekdays");
			$futureDate = date("c",$mod);
			
			$input = array('Activity' => array(
				'Description' => 'Respond to quote '.$quote['increment_id'],
				'StartDate' => $futureDate,
				'LongNotes' => 'Please ensure that the contact has been emailed or called within 48 hours of this inquiry. This is an automatically generated task.',
				'Notes' => 'Please ensure that the contact has been emailed or called within 48 hours of this inquiry. This is an automatically generated task. ',
				'ContactId' => $contactId,
				'AccountId' => $accountId,
				'OpportunityId' => $opportunityId,
				'ContactName' => $quote['firstname'].' '.$quote['lastname'],
				'UserId' => $accountManager['id'],
				'Type' => 'atToDo'
			));

			$activity = $this->transact2sdata("POST","activities",NULL,$input,TRUE);
			Mage::log( "Activity ID created is: ".$activity['slx:Activity_attr']['sdata:key']."<br>\n",null,'slx.log');
			if(!$activity) {Mage::log('ERROR Activity not created for '.$quote['increment_id'].' Request: '.$this->array2xml($input).' Response: '.print_r($history,true) ,null,'slx.log'); }
			

			//set the quote as imported... uncomment the below when live
			$model->setimported( array('quote_id'=>$quote['quote_id'],'value'=>'1') );
				
			//set the quote to proposal phase so we don't try import it again. 
			$theQuote->setStatus('50')->save();
			
			
			//CLEAR LOOP
			unset($info,$owner,$products,$item,$accountManager,$contactId,$accountId,$user,$input,
				$address,$addressId,$contact,$account,$emailExist,$DoNotSolicit,$opportunity,$opportunityId,$product_table,
				$notfound_table,$notfound,$assignment,$table,$date,$mod,$QuoteValidTill,$QuoteDate,$slxIDs,$RecipientEmail,
				$RecipientName,$translate,$template,$history,$futureDate,$activity,$theQuote);
			
		}
		
		$this->exportSubscribers(); //lets check for any new test shop subscribers and push them to SLX while we're at it.
		
	}	
	
	public function exportSubscribers(){
	
		//the only way this can work is by setting subscriber status to "not activated" as a flag that they've actually been processed. 
		
		$subscribers = Mage::getModel('newsletter/subscriber')->getCollection()->addStoreFilter(2);
		$subscribers->addFieldToFilter('subscriber_status',array('eq',1));
		$subscribers->showCustomerInfo(); 
		
		foreach ($subscribers as $subscriber) {
			
			$email = $subscriber->getSubscriberEmail(); 

			//does the email exist in contacts? 
			//it returns an array otherwise it returns false         
			if( $contact = $this->transact2sdata("GET","contacts","?where=Email eq '".$email."'",NULL,TRUE) )
			{
				//update contact saying they want to be emailed
				$input = array('Contact' => array(
					'DoNotSolicit' => 'false',
					'DoNotEmail' => 'false' //not sure how to set LeadSource on a Contact... 
				));
				
				$contactId = $contact['slx:Contact_attr']['sdata:key'];
				
				$newContact = $this->transact2sdata("PUT","contacts","('".$contactId."')",$input,TRUE);
				
				$newContactId = $contact['slx:Contact_attr']['sdata:key'];
				
				Mage::log( array('result'=>($newContactId)?TRUE:FALSE,'message'=>'Updated Contact','payload'=>$contact) , null , 'slx.log' );
			
			//is the lead already there? 
			}elseif( $lead = $this->transact2sdata("GET","leads","?where=Email eq '".$email."'",NULL,TRUE) ) {
			
				//update lead saying they want to be emailed
				$input = array('Lead' => array(
					'DoNotSolicit' => 'false',
					'DoNotEmail' => 'false',
					'LeadSource' => array('@attributes'=>array('sdata:key'=>'ABCDEF123456'), //CUSTOMIZE THIS lead source for Web 
											'value'=>NULL)
				));
				
				$leadId = $lead['slx:Lead_attr']['sdata:key'];
				
				$newLead = $this->transact2sdata("PUT","leads","('".$leadId."')",$input,TRUE);
				$newLeadId = $newLead['slx:Lead_attr']['sdata:key'];
				
				Mage::log( array('result'=>($newLeadId)?TRUE:FALSE,'message'=>'Update Lead','payload'=>$lead) , null , 'slx.log' );
			
			//otherwise create the lead
			}else{
				
				$notes = "Newsletter signup from ".Mage::app()->getStore(2)->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK)." at ".date('c');
					
				//this is the array to create a contact
				$input = array('Lead' => array(
					'Email' => $email,
					'FirstName' => $subscriber->getCustomerFirstname(),
					'LastName' => $subscriber->getCustomerLastname(),
					'DoNotSolicit' => 'false',
					'DoNotEmail' => 'false',
					'Notes' => $notes,
					'LeadSource' => array('@attributes'=>array('sdata:key'=>'ABCDEF123456'), 
											'value'=>NULL) //lead source for Web CUSTOMIZE THIS
				));
				
				$lead = $this->transact2sdata("POST","leads",NULL,$input,TRUE); 
				$leadId = $lead['slx:Lead_attr']['sdata:key'];
				
				
				Mage::log( array('result'=>($leadId)?TRUE:FALSE,'message'=>'Created Lead','payload'=>$lead) , null , 'slx.log' );

				
			}
			
			$subscriber->setStatus(2)->save();
			
		}
	
	}
	
	//this function converts SLX responses to array for evaluation
	function xml2array($contents, $get_attributes=1, $priority = 'tag') { 
		if(!$contents) return array(); 

		if(!function_exists('xml_parser_create')) { 
			//print "'xml_parser_create()' function not found!"; 
			return array(); 
		} 

		//Get the XML parser of PHP - PHP must have this module for the parser to work 
		$parser = xml_parser_create(''); 
		xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8"); # http://minutillo.com/steve/weblog/2004/6/17/php-xml-and-character-encodings-a-tale-of-sadness-rage-and-data-loss 
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0); 
		xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1); 
		xml_parse_into_struct($parser, trim($contents), $xml_values); 
		xml_parser_free($parser); 

		if(!$xml_values) return;//Hmm... 

		//Initializations 
		$xml_array = array(); 
		$parents = array(); 
		$opened_tags = array(); 
		$arr = array(); 

		$current = &$xml_array; //Refference 

		//Go through the tags. 
		$repeated_tag_index = array();//Multiple tags with same name will be turned into an array 
		foreach($xml_values as $data) { 
			unset($attributes,$value);//Remove existing values, or there will be trouble 

			//This command will extract these variables into the foreach scope 
			// tag(string), type(string), level(int), attributes(array). 
			extract($data);//We could use the array by itself, but this cooler. 

			$result = array(); 
			$attributes_data = array(); 
			 
			if(isset($value)) { 
				if($priority == 'tag') $result = $value; 
				else $result['value'] = $value; //Put the value in a assoc array if we are in the 'Attribute' mode 
			} 

			//Set the attributes too. 
			if(isset($attributes) and $get_attributes) { 
				foreach($attributes as $attr => $val) { 
					if($priority == 'tag') $attributes_data[$attr] = $val; 
					else $result['attr'][$attr] = $val; //Set all the attributes in a array called 'attr' 
				} 
			} 

			//See tag status and do the needed. 
			if($type == "open") {//The starting of the tag '<tag>' 
				$parent[$level-1] = &$current; 
				if(!is_array($current) or (!in_array($tag, array_keys($current)))) { //Insert New tag 
					$current[$tag] = $result; 
					if($attributes_data) $current[$tag. '_attr'] = $attributes_data; 
					$repeated_tag_index[$tag.'_'.$level] = 1; 

					$current = &$current[$tag]; 

				} else { //There was another element with the same tag name 

					if(isset($current[$tag][0])) {//If there is a 0th element it is already an array 
						$current[$tag][$repeated_tag_index[$tag.'_'.$level]] = $result; 
						$repeated_tag_index[$tag.'_'.$level]++; 
					} else {//This section will make the value an array if multiple tags with the same name appear together 
						$current[$tag] = array($current[$tag],$result);//This will combine the existing item and the new item together to make an array 
						$repeated_tag_index[$tag.'_'.$level] = 2; 
						 
						if(isset($current[$tag.'_attr'])) { //The attribute of the last(0th) tag must be moved as well 
							$current[$tag]['0_attr'] = $current[$tag.'_attr']; 
							unset($current[$tag.'_attr']); 
						} 

					} 
					$last_item_index = $repeated_tag_index[$tag.'_'.$level]-1; 
					$current = &$current[$tag][$last_item_index]; 
				} 

			} elseif($type == "complete") { //Tags that ends in 1 line '<tag />' 
				//See if the key is already taken. 
				if(!isset($current[$tag])) { //New Key 
					$current[$tag] = $result; 
					$repeated_tag_index[$tag.'_'.$level] = 1; 
					if($priority == 'tag' and $attributes_data) $current[$tag. '_attr'] = $attributes_data; 

				} else { //If taken, put all things inside a list(array) 
					if(isset($current[$tag][0]) and is_array($current[$tag])) {//If it is already an array... 

						// ...push the new element into that array. 
						$current[$tag][$repeated_tag_index[$tag.'_'.$level]] = $result; 
						 
						if($priority == 'tag' and $get_attributes and $attributes_data) { 
							$current[$tag][$repeated_tag_index[$tag.'_'.$level] . '_attr'] = $attributes_data; 
						} 
						$repeated_tag_index[$tag.'_'.$level]++; 

					} else { //If it is not an array... 
						$current[$tag] = array($current[$tag],$result); //...Make it an array using using the existing value and the new value 
						$repeated_tag_index[$tag.'_'.$level] = 1; 
						if($priority == 'tag' and $get_attributes) { 
							if(isset($current[$tag.'_attr'])) { //The attribute of the last(0th) tag must be moved as well 
								 
								$current[$tag]['0_attr'] = $current[$tag.'_attr']; 
								unset($current[$tag.'_attr']); 
							} 
							 
							if($attributes_data) { 
								$current[$tag][$repeated_tag_index[$tag.'_'.$level] . '_attr'] = $attributes_data; 
							} 
						} 
						$repeated_tag_index[$tag.'_'.$level]++; //0 and 1 index is already taken 
					} 
				} 

			} elseif($type == 'close') { //End of tag '</tag>' 
				$current = &$parent[$level-1]; 
			} 
		} 
		 
		return($xml_array); 
	}  
	 
	//this function converts arrays to SLX for submission 
	//attributes are handled specially, see below
	function array2xml($array,$xml = false){

		$level = error_reporting();
		error_reporting(E_ERROR | E_PARSE);

		if ($xml===false){
			// creating object of SimpleXMLElement
			$thexml = new SimpleXMLElement("<?xml version=\"1.0\"?><entry xmlns:sdata=\"http://schemas.sage.com/sdata/2008/1\" xmlns:slx=\"http://schemas.sage.com/dynamic/2007\"></entry>");
			$xml = $thexml->addChild("payload","","http://schemas.sage.com/sdata/2008/1");
		}
		
		foreach($array as $key => $value) {
			if(is_array($value)) {
				if( isset( $value['@attributes'] ) ){
					$subnode = $xml->addChild("$key","","http://schemas.sage.com/dynamic/2007");
					foreach($value['@attributes'] as $akey => $aval) $subnode->addAttribute($akey,$aval,"http://schemas.sage.com/sdata/2008/1");
					$this->array2xml($value['value'], $subnode);
				}elseif(!is_numeric($key)){
					$subnode = $xml->addChild("$key","","http://schemas.sage.com/dynamic/2007");
					$this->array2xml($value, $subnode);
				}
				else{
					$this->array2xml($value, $xml);
				}
			}
			else {
				$xml->addChild("$key","$value","http://schemas.sage.com/dynamic/2007");
			}
		}

		error_reporting($level);
		
		//saving generated xml file
		if (isset($thexml)) { 
			//make sure the xml is formatted nicely or sage wont like it.
			$dom = new DOMDocument("1.0");
			$dom->preserveWhiteSpace = false;
			$dom->formatOutput = true;
			$dom->loadXML($thexml->asXML());
			return $dom->saveXML();
			//return $thexml->asXML(); 
		}

	}
	 
	/*mode = GET, POST; 
	object = contacts, accounts, etc; To get a full list for yourself go to http://saleslogix.yourcompany.com:3333/sdata/slx/dynamic/-
	input = either a sql query or a full xml payload; 
	onlyEntry says whether to return full response or only entr(y|ies)*/
	function transact2sdata($method = "GET", $object="contacts", $get=NULL,$post=NULL,$onlyEntry=TRUE ){

		//CUSTOMIZE THIS! 
		$url = 'http://12.34.56.78:3333/sdata/slx/dynamic/-/'.$object; 
		if(!is_null($get)) $url .= $get; 
		$url = str_replace(" ","%20",$url);

		//Mage::log("hit",null,'slx.log');   
		
		if(!is_null($post)) $data = $this->array2xml($post);

		$ch = curl_init(); 
		curl_setopt($ch, CURLOPT_URL, $url); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
		curl_setopt($ch, CURLOPT_TIMEOUT, 12); 
		if($method!='GET'):
			//curl_setopt($ch, CURLOPT_POST, 1); 
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data); 
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method); 
		endif;
		curl_setopt($ch, CURLOPT_USERPWD, 'username:password'); //CUSTOMIZE THIS
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/atom+xml; type=entry"));
		$result = curl_exec($ch); 
		
	
		if ( curl_errno($ch) ) {
			$error = 'ERROR -> ' . curl_errno($ch) . ': ' . curl_error($ch);
		} else {
			$returnCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			switch($returnCode){
				case 404:
					$error = 'ERROR -> 404 Not Found';
					break;
				default:
					break;
			}
		}

		curl_close($ch);

		$response = $this->xml2array($result);
		
		
		
		if($onlyEntry==TRUE AND !$error){
			//if there are results, return them, otherwise return false
			if ($method == 'GET') { 
				if (isset($response['feed']['entry'])) 
				{	
					//if there is only one payload, return it or return the first one
					if(isset($response['feed']['entry']['sdata:payload']))
						return $response['feed']['entry']['sdata:payload']; 
					else
						return $response['feed']['entry'][0]['sdata:payload'];
				}
				else 
				{
					return FALSE;
				}
			}
			//if it's post method, we created and we return the create response
			else { if (isset($response['entry'])) return $response['entry']['sdata:payload']; else return $response; }
		}else{
			return array('result' => $result, 'response' => $response, 'error' => $error);
		}		
	}
	

	function convState($input) {
		$output = array(
			'Queensland' => 'QLD',
			'New South Wales' => 'NSW',
			'Western Australia' => 'WA',
			'Northern Territory' => 'NT',
			'Tasmania' => 'TAS',
			'Victoria' => 'VIC',
			'South Australia' => 'SA',
			'Australian Capital Territory' => 'ACT'
		);
		return $output[$input];
	}

	function convCountry($input) {
		//the reason for this function is that we will need to translate more countries in the future. 
		$output = array('Australia' => 'AUSTRALIA');
		if (isset($output[$input])) return $output[$input];
		else return "AUSTRALIA";
	}




	
}
?>

# php_saleslogix_api
PHP Integration with Sage Saleslogix API embedded in Magento / Cart2Quote

When I wrote this PHP toolkit for the Saleslogix API I didn't have a lot of example code to go on. 
I'm putting this here in case it helps someone who also has to integrate with the Saleslogix CRM API in PHP. 

The main code is found in app/code/local/Refractic/ExportQuotes/Model/Observer.php - this could be made into a stand-alone script. I've constructed this as a plugin for Magento. This addon has a cron that checks for new quotes in the Cart2Quote extension. If it finds them it pushes information into SLX. 

Due to what will possibly be quite minimal demand, I did not create an admin interface for this addon. 
You have to look in the script for the words "CUSTOMIZE HERE". That is where you need to set usernames, passwords, default email addresses, default SLX ids etc. 

This script lets you route quotes to the correct department and account manager in SLX based on the following criteria: 
- Manufacturer (of the manufacturer that holds the most products in the quote request)
- Country
- State

This script also does a fair bit of checking on what is in SLX already. If leads/contacts already exist it will use those. If account managers already are assigned, it will use those. 

It creates an account, contact, address, opportunity, activity history containing the quote details and follow up reminder. It also emails the account manager the details. 

The addon does not email the prospect as that is handled by cart2quote. Much thanks goes to cart2quote for their wonderful addon and that it comes with an excellent API itself. 

If you find bugs that you can fix, please contribute. If you end up making an admin control for this addon also please contribute. 
magento-bulk-attribute-edit
===========================

Stand alone script to edit Magento attribute values in bulk based on the category.  

USAGE
=============================================

Defined constants:

Line 8: DISABLE_INDEXER This constant is set to true by default, to switch the Indexing Mode 
on all the indexes to Manually Update, if it's set to false it will leave the indexes as they are.

Line 9: AUTO_MODE This constant is set to true by default so the other constants ROOT_FOLDER
and BASE_URL will be set by the script. If it's set to false (when the script can't resolve
the right values for the two constants above) you should also set the two constants at lines
35 and 36. Examples:
define('ROOT_FOLDER', '/home/mt_emanuel/public_html/LZUWQ8204/'); - root of the Magento installation folder
define('BASE_URL', 'http://emanuel.sv1.magetesting.com/LZUWQ8204/shell/web/manage.php'); - url of the script without query params

The script was uploaded to the folder "shell/web/" but this is not a requirement, it can be any
folder under the ROOT_FOLDER that is accessible from the browser. The shell folder has a .htaccess
that limits the access on apache server so another .htaccess needs to be added in the folder
"shell/web" with the following lines:
Order allow,deny
Allow from all


The login is the same as admin dashboard, so the same credentials should be used.

After the login, the left column will show a store switcher and a category tree. The store
switcher is only used to filter the categories.

The category has 3 actions:
- it can be moved - this will save the category under a new parent
- it can be selected - this will show a form with all the attributes that can be changed
(like mass update)
- the + sign in front of the category's name can display it childs

The attribute's form has 3 buttons - Export CSV, Review and Save.

Export - is not an ajax action, and will trigger a file download message. The file is .CSV
and will have the same info as ImportExport > Export > All products action, just that the
products collection is limited to the chosen category.

If we chose to Review the changes (before we Save them) then a grid with all the products
will be shown and the attributes changed will be the columns for the grid (with ID)
So if we change only one attribute, then we'll see just 2 columns ID and the attribute.
The grid can be filtered by the attributes, and also pagination is available.

Input and textarea fields can replace {{current}} with the actual value so, if the current
value of the name for a product is for example "HTC mobile" and in the form we have
Name:  "Some change {{current}} another change"
after the Save the Name will be "Some change HTC mobile another change".
Saving the attributes can be done in the form view or the grid view.
Both Save actions first set indexer based on the value of DISABLE_INDEXER

After we save the new values for the attributes we should change the processes to their
previous value and start the reindex manually.




LIMITATIONS
=============================================

Because the export csv button uses Magento's ImportExport module it will not work on versions
previous to 1.5.0.1. A workaround would be to manually export the products using Dataflow profiles
from the admin dashboard.

The csv file is written in a temp folder so the open_basedir restriction should allow Magento
to use the folder "/tmp".

On the review grid, the search will use the actual attributes, not the new ones (since they are 
not saved yet) and also the Save button will save all the products from the chosen category regardless 
of any filtering on this grid.

Because the script uses build in Magento functionality any custom modules that overrides this
functionality may cause bugs.

Some attributes like "sku" can't be changed, this is because the script uses the same collection
of attributes as Magento's mass update action. The attributes that can't be updated are the ones
marked as "Unique Value" in the Admin Attribute edit page (sku is one of them), the attributes not 
present in all the attributes sets of the chosen products and 'tier_price','gallery', 'media_gallery', 
'recurring_profile', 'group_price'. The later are removed (by attribute code) when the attributes 
collection is build for the form block in Magento.




TIME
=============================================

Most tests were done using sample data, so for 114 products saving name and qty took about 20 seconds.
In EE version 13.0.2 it takes about 12 - 15 minutes to export the csv file for 8600 products or to save new 
values for name and quantity.

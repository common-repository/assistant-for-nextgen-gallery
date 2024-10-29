=== Assistant for NextGEN Gallery ===
Contributors: 48hmorris
Tags: NextGEN gallery, uploads, image, Image Uploader, Image Optimizer, watermarks, resize, thumbnails, desktop
Requires at least: 4.8.5
Tested up to: 5.1.1
Requires PHP: 5.4
Stable tag: 1.0.9
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Save your web server and your time - optimize and upload images, add/delete galleries - all from a Windows or MACOS desktop app.

== Description ==
NextGEN Gallery image upload, image processing, and gallery management functions are moved from the website/browser to a desktop app running on your more powerful desktop system.<br><br>

**Features**<br>
* Super fast image processing - [Performance](https://sharp.dimens.io/en/stable/performance/) 
* Auto rotate, resize, adjust image quality, create thumbnails, and apply watermarks before uploading images
* Add/Remove NextGEN galleries
* Upload images to a NextGEN gallery
* Remove EXIF data from images
* <strong>Unlimited</strong> image file size
* Secure uploads using JWT Authentication for WP-API Plugin
* Imposes the same security restrictions as NextGEN Gallery
* Fast uploads tuned to your Wordpress configuration
* Per-user configuration settings

For more feature details and performance comparisons, visit [Assistant for NextGEN Gallery](https://nextgenassistant.com/).

**Usage**<br><br>
Once the Assistant for NextGEN Gallery plugin has been installed,
download and install the companion desktop app (Windows or MACOS) from [Assistant for NextGEN Gallery](https://nextgenassistant.com/) and start uploading your images.

**Requirements**<br>
* Windows 7 (64 bit) or Windows 10 (64 bit)
* OS X 10.9.5 or higher
* IIS Web Server – **not supported**
* WordPress multi-site – **not supported**

== Installation ==
Before installing Assistant for NextGEN Gallery, install the JWT Authentication for WP-API plugin and the NextGEN Gallery plugin (2.0 or higher).<br><br>
<strong>Automatic Installation</strong><br><br> 
> 1. Sign in to your WordPress site as an administrator.
> 2. In the main menu go to Plugins -> Add New.
> 3. Search for Assistant for NextGEN Gallery and click install.

After installing the plugin, download and install the companion desktop app available at [Assistant for NextGEN Gallery](https://nextgenassistant.com/).

== Frequently Asked Questions ==
= Is this plugin built/sponsored by IMAGELY - the author of NextGEN Gallery? =

No, Assistant for NextGEN Gallery has no affiliation with IMAGELY.

= Is the desktop app free to use? =

No, the desktop app is a SaaS (Software as a Service) program that requires a small annual subscription fee. A free, no risk trial version is available.

= Do you delete images or write images to disk?

No, your images and disk are safe. Processed images are held in memory and not written to disk.

= How does image processing/upload times compare to NextGEN Gallery?<br>

We're WAY faster.<br>

For example, upload 100 images (total size 428.6MB), resizing to 1200x1200 with jpg quality of 83%, no backup image. Images are dropped on the upload area.<br><br>
Desktop system - windows 10 - i7-3520M - 16GB memory - SSD<br>
Web server - StableHost.com starter system - 1 CPU - 2GB memory<br>
max_file_uploads: 50 upload_max_filesize: 400M post_max_size: 400M<br>

Assistant for NextGEN Gallery total time to upload - 16 seconds.<br>
NextGEN Gallery total time to upload - 10 minutes 22 seconds.<br>
We're **39 times** faster.<br>

Another upload with the previous setup but we add a watermark to the images.<br><br>
Assistant for NextGEN Gallery total time - 25 seconds.<br>
NextGEN Gallery total time - 13 minutes 23 seconds.<br>
We're **32 times** faster.

= Are there any benefits if I have already resized/optimized my images? =

Yes, use the "Just Upload" feature when uploading your images. Image thumbnails are created on your desktop system and uploaded to the gallery. This reduces your web server's load and decreases your overall image upload time. Depending on your workflow, uploads times can be greatly reduced.<br><br>
For example, upload 149 images (total size 24MB), no resize and no backup image. The folder containing the images is dropped on the upload area.<br><br>
Desktop system - windows 10 - i7-3520M - 16GB memory - SSD
Web server - StableHost.com starter system - 1 CPU - 2GB memory
max_file_uploads: 50 upload_max_filesize: 400M post_max_size: 400M

Assistant for NextGEN Gallery total upload time - 16 seconds.<br>
NextGEN Gallery total upload time - 7 minutes 12 seconds.<br>
We're **25 times** faster.

= There are a lot of other plugins that optimize images, why should I use Assistant for NextGEN Gallery? =

Moving all the image processing from your web server to your desktop reduces your web server load. Other solutions add additional web server load. Gallery thumbnails are also created on your desktop system which also reduces web server load. <br><br>
Also, our uploader will upload any size file even if it exceeds your server's file size limit.

= Why are my uploads slow? =

Your web hosting service could be limiting your upload speed or a plugin is limiting/disabling the uploads. As a test, disable all unneeded plugin and try your uploads. If you're using the Wordfence plugin, as a test, try disabling all "file_upload" rules in the plugin's firewall rules page http://yourwebsiteaddress.com/wp-admin/admin.php?page=WordfenceWAF&subpage=waf_options. You'll need to click on "SHOW ALL RULES" to see all the plugin's rules.

== Screenshots ==
1. Feature Tour
2. Example Upload

== Upgrade Notice ==

Initial release

== Changelog ==
 1.0.7 =
* Updated readme.txt - updated banner
 1.0.6 =
* Updated readme.txt - added screenshots

 1.0.5 =
* Initial release.

# Isekai AI Review
[中文文档](README-zh.md)

This extension require mediawiki Moderation extension.
Use AI to auto review revs in Moderation.

If you want to add more AI Review API, you can submit a issue.

## Useage
First, register at Aliyun: [https://www.aliyun.com/product/lvwang](https://www.aliyun.com/product/lvwang)

And then, install the Moderation extension: [https://github.com/edwardspec/mediawiki-moderation](https://github.com/edwardspec/mediawiki-moderation)

Install composer packages: (If you download the release, ignore it)
```php
composer update
```

Finally, add config in ```LocalSettings.php```:
```php
wfLoadExtension('IsekaiAIReview');

//config
$wgAIReviewEndpoint = 'cn-shanghai';
$wgAIReviewAccessKeyId = '阿里云的Access key id';
$wgAIReviewAccessKeySecret = '阿里云的Access key secret';
$wgAIReviewBizType = 'isekaiwiki';
$wgAIReviewRobotUID = 0; //The user account show in Moderation which approve revs
```
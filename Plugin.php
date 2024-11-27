<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * SMMS图床上传插件
 * 
 * @package SMMS
 * @author Nobu121
 * @version 1.1.0
 * @link https://github.com/nobu121/typecho-smms
 */
class SMMS_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法
     */
    public static function activate()
    {
        // 创建图片记录表
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $adapterType = $db->getAdapterName();  // 获取数据库类型

        // 根据数据库类型创建表
        if (strpos($adapterType, 'Mysql') !== false) {
            // MySQL 数据库
            $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}contents_pics` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `cid` int(11) NOT NULL,
                `url` varchar(255) NOT NULL,
                `hash` varchar(50) NOT NULL,
                `filename` varchar(255) NOT NULL,
                `width` int(11) DEFAULT NULL,
                `height` int(11) DEFAULT NULL,
                `token` varchar(100) NOT NULL,
                `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `cid` (`cid`),
                KEY `url` (`url`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } else {
            // SQLite 数据库
            $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}contents_pics` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `cid` INT NOT NULL,
                `url` VARCHAR(255) NOT NULL,
                `hash` VARCHAR(50) NOT NULL,
                `filename` VARCHAR(255) NOT NULL,
                `width` INT DEFAULT NULL,
                `height` INT DEFAULT NULL,
                `token` VARCHAR(100) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );");

            // 为 SQLite 创建索引
            $db->query("CREATE INDEX IF NOT EXISTS `contents_pics_cid` ON `{$prefix}contents_pics` (`cid`);");
            $db->query("CREATE INDEX IF NOT EXISTS `contents_pics_url` ON `{$prefix}contents_pics` (`url`);");
        }

        // 添加路由拦截器
        Typecho_Plugin::factory('admin/common.php')->begin = array('SMMS_Plugin', 'routeIntercept');

        Helper::addAction('smms', 'SMMS_Action');
        Typecho_Plugin::factory('admin/write-post.php')->bottom = array('SMMS_Plugin', 'renderButton');
        Typecho_Plugin::factory('admin/write-page.php')->bottom = array('SMMS_Plugin', 'renderButton');

        return _t('插件已经激活，请前往设置填写 SMMS Token');
    }

    /**
     * 禁用插件方法
     */
    public static function deactivate()
    {
        Helper::removeAction('smms');
        return _t('插件已被禁用');
    }

    /**
     * 插件配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $token = new Typecho_Widget_Helper_Form_Element_Text(
            'token',
            null,
            '',
            _t('SMMS Token'),
            _t('请输入从 SMMS 获取的 API Token')
        );
        $form->addInput($token->addRule('required', _t('Token不能为空')));

        $domain = new Typecho_Widget_Helper_Form_Element_Radio(
            'domain',
            [
                'smms.app' => _t('国内域名 (smms.app)'),
                'sm.ms' => _t('国外域名 (sm.ms)')
            ],
            'smms.app',
            _t('服务器域名'),
            _t('选择 SMMS 图床的服务器域名')
        );
        $form->addInput($domain);

        $localPath = new Typecho_Widget_Helper_Form_Element_Text(
            'localPath',
            null,
            'smms',  // 默认值为 smms
            _t('本地存储目录'),
            _t('图片同步到本地的存储目录名，位于 /usr/uploads/ 下。留空则不保存到本地')
        );
        $form->addInput($localPath);
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 上传文件处理函数
     */
    public static function uploadHandle($file)
    {
        if (empty($file['name'])) {
            return false;
        }
        // 检查文件大小限制
        if ($file['size'] > 5 * 1024 * 1024) { // 5MB 限制
            throw new Exception(_t('图片大小不能超过 5MB'));
        }

        // 判断是否是图片
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'])) {
            throw new Exception(_t('文件类型不支持'));
        }

        // 获取插件配置
        $options = Helper::options()->plugin('SMMS');
        if (empty($options->token)) {
            throw new Exception(_t('请先配置 SMMS Token'));
        }

        // 检查是否需要本地存储
        $needLocalStore = !empty($options->localPath);

        if ($needLocalStore) {
            // 使用配置的目录名
            $uploadDir = __TYPECHO_ROOT_DIR__ . '/usr/uploads/' . trim($options->localPath, '/');

            // 创建上传目录
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0777, true)) {
                    throw new Exception(_t('无法创建上传目录'));
                }
            }
        }

        // 先上传到 SMMS
        $data = [
            'smfile' => new CURLFile($file['tmp_name'], $file['type'], $file['name'])
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://' . $options->domain . '/api/v2/upload',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $options->token
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception(_t('上传失败，HTTP状态码：') . $httpCode);
        }

        $result = json_decode($response, true);

        if (!$result || $result['success'] !== true) {
            // 处理重复图片的情况
            if (strpos($result['message'], 'Image upload repeated limit') === 0) {
                preg_match('/exists at: (.+)$/', $result['message'], $matches);
                if (!empty($matches[1])) {
                    // 从数据库查询已存在的图片信息
                    $db = Typecho_Db::get();
                    $row = $db->fetchRow($db->select()
                        ->from('table.contents_pics')
                        ->where('url = ?', $matches[1])
                        ->limit(1));

                    if ($row) {
                        if ($needLocalStore) {
                            $smmsFileName = basename($matches[1]);
                            $localPath = $uploadDir . '/' . $smmsFileName;
                            if (!file_exists($localPath)) {
                                copy($file['tmp_name'], $localPath);
                            }
                        }

                        // 返回数据库中的记录
                        return [
                            'name' => $file['name'],
                            'path' => $row['url'],
                            'hash' => $row['hash'],
                            'width' => $row['width'],
                            'height' => $row['height'],
                            'size' => $file['size'],
                            'type' => $file['type'],
                            'mime' => $file['type']
                        ];
                    } else {
                        // 如果数据库中没有记录，则创建新记录
                        $imageInfo = getimagesize($file['tmp_name']);
                        $hash = md5_file($file['tmp_name']);

                        // 获取当前文章 ID
                        $cid = isset($_POST['cid']) ? intval($_POST['cid']) : 0;

                        // 插入新记录
                        $db->query($db->insert('table.contents_pics')->rows([
                            'url' => $matches[1],
                            'hash' => $hash,
                            'filename' => $file['name'],
                            'width' => $imageInfo[0] ?? null,
                            'height' => $imageInfo[1] ?? null,
                            'token' => $options->token,
                            'cid' => $cid
                        ]));

                        return [
                            'name' => $file['name'],
                            'path' => $matches[1],
                            'hash' => $hash,
                            'width' => $imageInfo[0] ?? null,
                            'height' => $imageInfo[1] ?? null,
                            'size' => $file['size'],
                            'type' => $file['type'],
                            'mime' => $file['type']
                        ];
                    }
                }
            }
            throw new Exception(_t('上传失败：') . ($result['message'] ?? '未知错误'));
        }

        // 从 SMMS 返回的 URL 中提取文件名
        $smmsFileName = basename($result['data']['url']);

        if ($needLocalStore) {
            $localPath = $uploadDir . '/' . $smmsFileName;
            // SMMS 上传成功后保存到本地
            copy($file['tmp_name'], $localPath);
        }

        return [
            'name' => $file['name'],
            'path' => $result['data']['url'],
            'hash' => $result['data']['hash'],
            'width' => $result['data']['width'],
            'height' => $result['data']['height'],
            'size' => $file['size'],
            'type' => $file['type'],
            'mime' => $file['type']
        ];
    }

    /**
     * 渲染上传按钮和面板
     */
    public static function renderButton()
    {
        $options = Helper::options();
        $url = Typecho_Common::url('action/smms', $options->index);

        echo <<<HTML
<script>
window.smmsOptions = {
    url: '{$url}?do=',
    type: 'smms',
};
</script>
<script src="{$options->pluginUrl}/SMMS/assets/js/upload.js"></script>
HTML;
    }

    /**
     * 删除图片处理函数
     */
    public static function deleteHandle($url, $hash, $token)
    {
        // 如果配置了本地存储，删除本地文件
        $options = Helper::options()->plugin('SMMS');
        if (!empty($options->localPath)) {
            $localPath = __TYPECHO_ROOT_DIR__ . '/usr/uploads/' . trim($options->localPath, '/') . '/' . basename($url);
            if (file_exists($localPath)) {
                unlink($localPath);
            }
        }

        // 从 SMMS 删除图片，使用保存的 token
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://' . $options->domain . '/api/v2/delete/' . $hash,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $token
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception(_t('删除失败，HTTP状态码：') . $httpCode);
        }

        $result = json_decode($response, true);
        if (!$result || $result['success'] !== true) {
            throw new Exception(_t('删除失败：') . ($result['message'] ?? '未知错误'));
        }

        return true;
    }

    /**
     * 路由拦截器
     */
    public static function routeIntercept()
    {
        $request = Typecho_Request::getInstance();
        $response = Typecho_Response::getInstance();

        // 获取当前请求的路径
        $requestUrl = $request->getRequestUrl();

        if (strpos($requestUrl, 'write-post.php') !== false && !$request->get('cid')) {
            self::createDraft('post', $response);
        } else if (strpos($requestUrl, 'write-page.php') !== false && !$request->get('cid')) {
            self::createDraft('page', $response);
        }
    }

    /**
     * 新建文章、页面拦截
     */
    private static function createDraft($type, $response)
    {
        $db = Typecho_Db::get();
        $user = Typecho_Widget::widget('Widget_User');

        // 创建空白内容
        $insert = $db->insert('table.contents')
            ->rows([
                'title' => '',
                'slug' => 'draft-' . date('YmdHis'),
                'created' => time(),
                'modified' => time(),
                'text' => '<!--markdown-->',
                'authorId' => $user->uid,
                'type' => $type . '_draft',
                'status' => 'publish'
            ]);

        $insertId = $db->query($insert);

        // 构建重定向URL
        $redirectUrl = Typecho_Common::url(
            'write-' . $type . '.php?cid=' . $insertId,
            Helper::options()->adminUrl
        );

        $response->setStatus(302);
        $response->setHeader('Location', $redirectUrl);
        exit;
    }
}

<?php
class SMMS_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $options;

    public function __construct($request, $response, $params = NULL)
    {
        parent::__construct($request, $response, $params);
        $this->options = Helper::options()->plugin('SMMS');
    }

    public function execute()
    {
    }

    public function action()
    {
        switch ($_GET['do']) {
            case 'upload':
                $this->upload();
                break;
            case 'delete':
                $this->delete();
                break;
            case 'getImages':
                $this->getImages();
                break;
            default:
                $this->response->throwJson([
                    'success' => false,
                    'message' => '未知操作'
                ]);
        }
    }

    /**
     * 上传图片接口
     */
    public function upload()
    {
        try {
            $file = $_FILES['file'];
            $cid = isset($_POST['cid']) ? intval($_POST['cid']) : 0;

            $result = SMMS_Plugin::uploadHandle($file);

            // 保存图片记录
            if ($cid > 0) {
                $this->saveImageRecord(
                    $cid,
                    $result['path'],
                    $result['hash'],
                    $result['name'],
                    $result['width'] ?? null,
                    $result['height'] ?? null
                );
            }

            $this->response->throwJson([
                'success' => true,
                'url' => $result['path'],
                'filename' => $result['name'],
                'hash' => $result['hash'],
                'width' => $result['width'] ?? null,
                'height' => $result['height'] ?? null
            ]);

        } catch (Exception $e) {
            $this->response->throwJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 保存图片记录
     */
    private function saveImageRecord($cid, $url, $hash, $filename, $width = null, $height = null)
    {
        $db = Typecho_Db::get();
        $db->query($db->insert('table.contents_pics')->rows([
            'cid' => $cid,
            'url' => $url,
            'hash' => $hash,
            'filename' => $filename,
            'width' => $width,
            'height' => $height,
            'token' => $this->options->token
        ]));
    }

    /**
     * 获取文章图片列表
     */
    public function getImages()
    {
        try {
            $cid = isset($_GET['cid']) ? intval($_GET['cid']) : 0;
            if ($cid <= 0) {
                throw new Exception(_t('缺少文章ID'));
            }

            $db = Typecho_Db::get();
            $rows = $db->fetchAll($db->select('id', 'url', 'hash', 'filename', 'width', 'height', 'created_at')
                ->from('table.contents_pics')
                ->where('cid = ?', $cid)
                ->order('created_at', Typecho_Db::SORT_ASC));

            $this->response->throwJson([
                'success' => true,
                'data' => $rows
            ]);
        } catch (Exception $e) {
            $this->response->throwJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 删除图片接口
     */
    public function delete()
    {
        try {
            if (empty($_POST['url'])) {
                throw new Exception(_t('缺少图片地址'));
            }

            $url = $_POST['url'];
            $db = Typecho_Db::get();

            // 获取图片记录
            $row = $db->fetchRow($db->select()
                ->from('table.contents_pics')
                ->where('url = ?', $url));

            if (!$row) {
                throw new Exception(_t('找不到图片记录'));
            }

            // 使用保存的 token 删除图片
            SMMS_Plugin::deleteHandle($url, $row['hash'], $row['token']);

            // 删除数据库记录
            $db->query($db->delete('table.contents_pics')
                ->where('url = ?', $url));

            $this->response->throwJson([
                'success' => true,
                'message' => '删除成功'
            ]);
        } catch (Exception $e) {
            $this->response->throwJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
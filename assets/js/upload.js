$(document).ready(function () {
  // 添加样式
  $("body").append(`
        <style>
            .typecho-option-tabs .w-50 { width: 33.22222% }
            #tab-smms { margin: 1em 0; border: 1px dashed #d9d9d6 }
            .smms-upload-btn { 
                padding: 15px;
                background-color: #fff;
                color: #467b96;
                font-size: .92857em;
                text-align: center;
                cursor: pointer;
                position: relative;
                overflow: hidden;
            }
            .smms-list {
                margin: 0;
                padding: 0 10px;
                max-height: 450px;
                overflow: auto;
                word-break: break-all;
                background-color: #fff;
            }
            .smms-list .smms-item {
                display: flex;
                padding: 8px 0;
                border-top: 1px dashed #d9d9d6;
                align-items: center;
                position: relative;
            }
            .smms-list .smms-item img {
                width: 48px;
                height: 48px;
                min-width: 48px;
                min-height: 48px;
                object-fit: contain;
                border-radius: 2px;
            }
            .smms-list .img-info {
                padding: 0 8px;
                flex: 1;
                min-width: 0;
            }
            .smms-list .img-name {
                display: block;
                max-width: 100%;
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
                cursor: pointer;
            }
            .smms-list .delete {
                color: red !important;
            }

            .typecho-option-tabs li:last-child a {
                border-left: none;
            }
            .upload-area::after {
                content: "（图片请上传至图床）";
                display: block;
                font-size: 0.9em;
                color: #999;
                margin-top: 5px;
            }
            .smms-list .img-size {
                color: #999;
                font-size: 10px;
                display: block;
            }
            .smms-list .img-actions {
                display: flex;
                align-items: center;
                gap: 4px;
                font-size: 12px;
                color: #999;
            }
            .smms-list .img-actions a,
            .smms-list .img-actions a:hover {
                color: #467b96;
                cursor: pointer;
                text-decoration: none;
            }
            .smms-list .img-actions a:hover {
                color: #6aa1bb;
            }
            .smms-list .img-actions .separator {
                color: #ddd;
            }
            
            .smms-preview-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.8);
                display: none;
                justify-content: center;
                align-items: center;
                z-index: 9999;
                cursor: zoom-out;
            }
            .smms-preview-img {
                max-width: 90%;
                max-height: 90vh;
                object-fit: contain;
                border-radius: 4px;
                box-shadow: 0 0 20px rgba(0, 0, 0, 0.3);
            }
        </style>
    `);

  if ($(".smms-preview-overlay").length === 0) {
    $("body").append(`
            <div class="smms-preview-overlay">
                <img class="smms-preview-img" src="" alt="">
            </div>
        `);
  }

  // 添加标签页
  $(".typecho-option-tabs").append(
    '<li class="w-50 smms-tab-btn"><a href="#tab-smms">图床 <span class="balloon"></span> </a></li>'
  );

  // 添加内容面板和提示信息
  $("#edit-secondary").append(`
        <div id="tab-smms" class="tab-content hidden">
            <div class="smms-upload-btn">选择图片上传</div>
            <input type="file" id="smms-upload" style="display:none" accept="image/*" multiple max="5" />
            <div class="smms-list"></div>
        </div>
      
    `);

  // 处理标签切换
  $("#edit-secondary .typecho-option-tabs .smms-tab-btn").click(function () {
    $("#edit-secondary .typecho-option-tabs li").removeClass("active");
    $(this).addClass("active");
    $(this).parents("#edit-secondary").find(".tab-content").addClass("hidden");

    var selected_tab = $(this).find("a").attr("href");
    $(selected_tab).removeClass("hidden");

    return false;
  });

  // 处理上传按钮点击
  $(".smms-upload-btn").click(function () {
    $("#smms-upload").click();
  });

  // 处理文件上传
  $("#smms-upload").change(function () {
    var files = Array.from(this.files);

    var $uploadBtn = $(".smms-upload-btn");
    var totalFiles = files.length;
    var currentFile = 0;

    if (files.length === 0) return;

    function updateStatus() {
      $uploadBtn.text(`正在上传 (${currentFile + 1}/${totalFiles})`);
    }

    function uploadFile(index) {
      if (index >= files.length) {
        $uploadBtn.text("选择图片上传");
        return;
      }

      currentFile = index;
      updateStatus();

      var formData = new FormData();
      formData.append("file", files[index]);
      formData.append("cid", getCurrentCid());

      function handleUploadLimitError(message) {
        if (message.includes("Minute")) {
          alert("已达到每分钟20张的上传限制，请稍后再试");
        } else if (message.includes("Hour")) {
          alert("已达到每小时100张的上传限制，请稍后再试");
        } else if (message.includes("Day")) {
          alert("已达到每天200张的上传限制，请等待明天");
        } else if (message.includes("Week")) {
          alert("已达到每周500张的上传限制，请等待下周");
        } else if (message.includes("Month")) {
          alert("已达到每月1000张的上传限制，请等待下月");
        } else {
          alert("已达到上传频率限制，请稍后再试");
        }
      }

      $.ajax({
        url: window.smmsOptions.url + "upload",
        type: "POST",
        data: formData,
        processData: false,
        contentType: false,
        success: function (resp) {
          if (resp.success) {
            if (
              addImageItem(resp.url, resp.filename, resp.width, resp.height)
            ) {
              updateImageCount();
            }
          } else {
            // 处理 SMMS 上传限制的错误提示
            if (
              resp.message &&
              resp.message.includes("Upload Frequency Limit")
            ) {
              handleUploadLimitError(resp.message);
              // 停止后续上传
              $uploadBtn.text("选择图片上传（最多5张）");
              return;
            }
            alert(resp.message || "上传失败");
          }
        },
        error: function (xhr, status, error) {
          console.error("Upload error:", status, error);
          alert("上传失败: " + JSON.stringify(error));
        },
        complete: function () {
          uploadFile(index + 1);
        },
      });
    }

    // 开始上传第一个文件
    uploadFile(0);
    this.value = "";
  });

  // 添加图片到列表
  function addImageItem(url, filename, width, height) {
    // 检查是否已存在相同URL的图片
    const existingImage = $(".smms-list .smms-item").filter(function () {
      return $(this).find("img").attr("src") === url;
    });

    if (existingImage.length > 0) {
      // 找到重复图片，高亮显示并提示用户
      const $duplicate = existingImage.first();
      alert("该图片已经上传过了！");

      // 高亮效果
      $duplicate
        .css("background-color", "#fff3cd")
        .animate({ backgroundColor: "#ffffff" }, 2000);

      return false;
    }

    var $item = $(`
            <div class="smms-item">
                <img src="${url}" alt="${filename}" style="cursor: zoom-in;">
                <div class="img-info">
                    <a class="img-name" title="点击复制链接" data-url="${url}">${filename}</a>
                    <span class="img-size">${
                      width && height ? `${width} × ${height}` : ""
                    }</span>
                    <div class="img-actions">
                        <a class="insert-md" title="插入 Markdown">插入</a>
                        <span class="separator">|</span>
                        <a class="insert-html" title="插入 HTML">插入HTML</a>
                        <span class="separator">|</span>
                        <a class="delete" title="删除图片">删除</a>
                    </div>
                </div>
            </div>
        `);

    // 绑定 Markdown 插入事件
    $item.find(".insert-md").click(function () {
      var text = `\n![${filename}](${url})\n`;

      if (window.vditor) {
        window.vditor.insertValue(text);
      } else {
        // 如果找不到 vditor 实例，回退到原来的 textarea 插入方式
        var textarea = $('textarea[name="text"]');
        textarea.focus().insert({ text: text });
      }
    });

    // 绑定 HTML 插入事件
    $item.find(".insert-html").click(function () {
      var text = `\n<img src="${url}" alt="${filename}" title="${filename}"${
        width && height ? ` width="${width}" height="${height}"` : ""
      }>\n`;

      if (window.vditor) {
        window.vditor.insertValue(text);
      } else {
        var textarea = $('textarea[name="text"]');
        textarea.focus().insert({ text: text });
      }
    });

    // 绑定删除事件
    $item.find(".delete").click(function () {
      if (confirm("确定要删除这张图片吗？")) {
        var $li = $(this).closest(".smms-item");
        var $imgName = $li.find(".img-name");
        var $actions = $li.find(".img-actions");

        // 禁用所有操作按钮
        $actions.find("a").css("pointer-events", "none").css("opacity", "0.5");
        $imgName
          .css("pointer-events", "none")
          .css("opacity", "0.5")
          .text("正在删除...");

        $.ajax({
          url: window.smmsOptions.url + "delete",
          type: "POST",
          data: { url: url },
          success: function (resp) {
            if (
              resp.success ||
              (resp.message && resp.message.includes("File already deleted"))
            ) {
              // 无论是删除成功还是文件已被删除，都直接移除本地图片
              $li.fadeOut("fast", function () {
                $(this).remove();
                updateImageCount();
              });
            } else {
              alert(resp.message || "删除失败");
              // 恢复按钮和文字状态
              $actions.find("a").css("pointer-events", "").css("opacity", "");
              $imgName
                .css("pointer-events", "")
                .css("opacity", "")
                .text(filename);
            }
          },
          error: function (xhr, status, error) {
            console.error("Delete error:", status, error);
            alert("删除失败: " + error);
            $actions.find("a").css("pointer-events", "").css("opacity", "");
            $imgName
              .css("pointer-events", "")
              .css("opacity", "")
              .text(filename);
          },
        });
      }
    });

    // 绑定图片预览事件
    $item.find("img").click(function (e) {
      e.stopPropagation();
      const $overlay = $(".smms-preview-overlay");
      const $previewImg = $overlay.find(".smms-preview-img");

      $previewImg.attr("src", url);
      $overlay.css("display", "flex").hide().fadeIn(200);
    });

    // 添加复制链接功能
    $item.find(".img-name").click(function () {
      const url = $(this).data("url");
      const $this = $(this);

      // 创建临时输入框
      const tempInput = document.createElement("input");
      tempInput.style.position = "absolute";
      tempInput.style.left = "-9999px";
      tempInput.value = url;
      document.body.appendChild(tempInput);

      // 选择并复制文本
      tempInput.select();
      try {
        document.execCommand("copy");
        // 显示复制成功提示
        const originalText = $this.text();
        $this.text("已复制");
        setTimeout(() => {
          $this.text(originalText);
        }, 1000);
      } catch (err) {
        console.error("复制失败:", err);
        alert("复制失败，请手动复制");
      }

      // 移除临时输入框
      document.body.removeChild(tempInput);
    });

    $(".smms-list").prepend($item);
    updateImageCount();
    return true;
  }

  // 添加 insert 方法
  (function ($) {
    $.fn.extend({
      insert: function (value) {
        value = $.extend(
          {
            text: "",
          },
          value
        );

        var dthis = $(this)[0];
        if (document.selection) {
          $(dthis).focus();
          var fus = document.selection.createRange();
          fus.text = value.text;
          $(dthis).focus();
        } else if (dthis.selectionStart || dthis.selectionStart == "0") {
          var start = dthis.selectionStart;
          var end = dthis.selectionEnd;
          dthis.value =
            dthis.value.substring(0, start) +
            value.text +
            dthis.value.substring(end, dthis.value.length);
        } else {
          this.value += value.text;
          this.focus();
        }
        return $(this);
      },
    });
  })(jQuery);

  // 获取当前文章ID
  function getCurrentCid() {
    const urlParams = new URLSearchParams(window.location.search);
    const cid = urlParams.get("cid");

    if (!cid) {
      // 使用ID选择器定位保存草稿按钮
      const $saveBtn = $("#btn-save");
      if ($saveBtn.length) {
        $saveBtn.click();
        return 0;
      } else {
        alert("无法获取文章ID，请手动保存草稿后再上传图片");
        return 0;
      }
    }

    return cid;
  }

  // 修改加载已有图片的函数
  function loadExistingImages() {
    const cid = getCurrentCid();
    if (!cid) {
      updateImageCount();
      return;
    }

    $.ajax({
      url: window.smmsOptions.url + "getImages",
      type: "GET",
      data: { cid: cid },
      success: function (resp) {
        if (resp.success && resp.data) {
          resp.data.forEach(function (item) {
            addImageItem(item.url, item.filename, item.width, item.height);
          });
        }
        updateImageCount();
      },
    });
  }

  // 页面加载完成后初始化数量为0
  updateImageCount();

  // 页面加载完成后加载已有图片
  loadExistingImages();

  // 更新图片数量气泡
  function updateImageCount() {
    const count = $(".smms-list .smms-item").length;
    const $balloon = $(".smms-tab-btn .balloon");

    if (count > 0) {
      $balloon.text(count).show();
    } else {
      $balloon.hide();
    }
  }

  $(document).on("click", ".smms-preview-overlay", function () {
    $(this).fadeOut(200);
  });

  $(document).on("click", ".smms-preview-img", function (e) {
    e.stopPropagation();
  });

  $(document).keydown(function (e) {
    if (e.key === "Escape") {
      $(".smms-preview-overlay").fadeOut(200);
    }
  });
});

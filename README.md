# AI‑Blog 🧠

> **一个极简、免配置、开箱即用的 PHP 博客系统**  
> 轻量到只有 **单个 PHP 文件**，却完整覆盖个人博客的核心需求 ✨  
> 适合个人记录、项目文档、内网笔记、AI 自动生成站点
<img width="727" height="689" alt="image" src="https://github.com/user-attachments/assets/3a0fabc7-118d-49db-9983-94062a9e09a2" />
<img width="942" height="688" alt="image" src="https://github.com/user-attachments/assets/919ef7ef-522e-4681-a7ae-b30d91168efb" />
<img width="984" height="834" alt="image" src="https://github.com/user-attachments/assets/6cefa552-1822-4dce-a131-94c00a888055" />


## ✨ 核心特性

### ☁️ 真正极简
- **仅一个 `index.php`**
- 无需 Composer、无需框架、无需 Nginx 特殊配置
- 复制 → 上传 → 访问 → 完成安装

### ⚡ 零运维成本
- **支持 SQLite / MySQL / PostgreSQL**
- 首次访问自动进入安装向导
- 自动建表、自动迁移、自动防护目录

### 🧠 为 AI 二开而生
- **无抽象层、无多余封装**
- 逻辑线性、函数式、几乎无“黑盒”
- 非常适合：
  - AI 自动阅读并修改
  - 快速裁剪为 API / CMS / 微站
  - 作为 AI 生成的“最小可运行示例”

### 🔐 内置安全与限制
- CSRF 防护
- 登录限流（Rate Limit）
- 管理入口随机路径
- `data/` 目录自动 `.htaccess` 拒绝访问

### 🎨 终端美学 UI
- 类 Linux Shell / Vim 风格
- 响应式设计，手机端友好
- 纯 CSS，无 JS 框架

---

## 📦 部署方式（30 秒）

```bash
# 1. 克隆仓库
git clone https://github.com/7133017/ai-blog.git
cd ai-blog

# 2. 上传到支持 PHP 的服务器
# （虚拟主机 / VPS / 内网 / Docker 均可）

# 3. 浏览器访问
https://your-domain.com/
```

✅ **PHP ≥ 7.2 即可运行**

---

## 🛠 使用说明

| 功能 | 说明 |
|----|----|
| 写文章 | 支持 Markdown / 纯文本 |
| 标签 | 多标签、标签聚合页 |
| 草稿 | 仅管理员可见 |
| 分页 | 自动分页 |
| 管理 | 内置登录态、登出、删除 |

---

## 🧩 AI 二次开发示例

AI 可以轻松做到：

- ✅ 改成 **API 博客**
- ✅ 增加 **评论系统**
- ✅ 接入 **OpenAI / 本地 LLM**
- ✅ 改为 **文档系统 / Wiki**
- ✅ 精简成 **只读博客**
- ✅ 增加 **RSS / Sitemap**

> 因为代码足够“笨”，所以 AI 更容易“懂”。

---

## 📁 目录结构

```
ai-blog/
├── index.php        # 唯一入口（全部逻辑）
├── data/            # 自动生成
│   ├── blog.sqlite
│   ├── config.php
│   └── ratelimit/
└── README.md
```

---

## 🔒 安全建议

- 将 `data/` 放在 Web 根目录外（可选）
- 使用 HTTPS
- 定期备份 `data/config.php`

---

## 🧠 设计哲学

> **“能用一个 PHP 文件解决的问题，就不要用十个。”**

AI‑Blog 不是 CMS，也不是框架  
它是一个 **可被人类和 AI 同时理解的最小博客实现**

---

## 📄 License

MIT © 7133017

---


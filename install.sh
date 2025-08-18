#!/bin/bash

echo -e ''
echo -e "\033[32m========Sing-Box for OPNsense一键安装脚本=========\033[0m"
echo -e ''

# 定义颜色变量
GREEN="\033[32m"
YELLOW="\033[33m"
RED="\033[31m"
CYAN="\033[36m"
RESET="\033[0m"

# 定义目录变量
ROOT="/usr/local"
BIN_DIR="$ROOT/bin"
WWW_DIR="$ROOT/www"
CONF_DIR="$ROOT/etc"
MENU_DIR="$ROOT/opnsense/mvc/app/models/OPNsense"
RC_DIR="$ROOT/etc/rc.d"
PLUGINS="$ROOT/etc/inc/plugins.inc.d"
ACTIONS="$ROOT/opnsense/service/conf/actions.d"
RC_CONF="/etc/rc.conf.d/"
CONFIG_FILE="/conf/config.xml"
TMP_FILE="/tmp/config.xml.tmp"
TIMESTAMP=$(date +%F-%H%M%S)
BACKUP_FILE="/conf/config.xml.bak.$TIMESTAMP"

# 定义日志函数
log() {
    local color="$1"
    local message="$2"
    echo -e "${color}${message}${RESET}"
}

# 创建目录
log "$YELLOW" "创建目录..."
mkdir -p "$CONF_DIR/sing-box" || log "$RED" "目录创建失败！"

# 复制文件
log "$YELLOW" "复制文件..."
log "$YELLOW" "生成菜单..."
log "$YELLOW" "生成服务..."
log "$YELLOW" "添加权限..."
chmod +x bin/*
chmod +x rc.d/*
cp -f bin/* "$BIN_DIR/" || log "$RED" "bin 文件复制失败！"
cp -f www/* "$WWW_DIR/" || log "$RED" "www 文件复制失败！"
cp -f rc.d/* "$RC_DIR/" || log "$RED" "rc.d 文件复制失败！"
cp -R -f menu/* "$MENU_DIR/" || log "$RED" "menu 文件复制失败！"
cp -f rc.conf/* "$RC_CONF/" || log "$RED" "rc.conf 文件复制失败！"
cp -f plugins/* "$PLUGINS/" || log "$RED" "plugins 文件复制失败！"
cp -f actions/* "$ACTIONS/" || log "$RED" "actions 文件复制失败！"
cp -R -f conf/* "$CONF_DIR/sing-box/" || log "$RED" "sing-box 配置文件复制失败！"

# 新建订阅程序
log "$YELLOW" "增加订阅功能..."
if [ -f /usr/local/etc/sing-box/sub/sub.sh ]; then
  cat >/usr/bin/sub <<EOF
#!/bin/sh
bash /usr/local/etc/sing-box/sub/sub.sh
EOF
chmod +x /usr/bin/sub
else
  log "$RED" "订阅脚本sub.sh 不存在，跳过创建 /usr/bin/sub"
fi

# 启动Tun接口
log "$YELLOW" "启动sing-box..."
service sing-box restart > /dev/null 2>&1
echo ""

# 备份配置文件
cp "$CONFIG_FILE" "$BACKUP_FILE" || {
  echo "配置备份失败，终止操作！"
  echo ""
  exit 1
}

# 添加tun接口
log "$YELLOW" "添加 tun_3000 接口..."
if grep -q "<if>tun_3000</if>" "$CONFIG_FILE"; then
  log "$CYAN" "存在同名接口，忽略"
else
  awk '
  BEGIN { inserted = 0 }
  {
    print
    if ($0 ~ /<\/lo0>/ && inserted == 0) {
      print "    <opt10>"
      print "      <if>tun_3000</if>"
      print "      <descr>TUN</descr>"
      print "      <enable>1</enable>"
      print "    </opt10>"
      inserted = 1
    }
  }
  ' "$CONFIG_FILE" > "$TMP_FILE" && mv "$TMP_FILE" "$CONFIG_FILE"
  echo "接口添加完成"
fi
echo ""

# 添加防火墙规则（允许TUN子网互访问）
log "$YELLOW" "添加防火墙规则..."
if grep -q "c0398153-597b-403b-9069-734734b46497" "$CONFIG_FILE"; then
  log "$CYAN" "存在同名规则，忽略"
else
  awk '
  /<filter>/ {
    print
    print "    <rule uuid=\"c0398153-597b-403b-9069-734734b46497\">"
    print "      <type>pass</type>"
    print "      <interface>opt10</interface>"
    print "      <ipprotocol>inet</ipprotocol>"
    print "      <statetype>keep state</statetype>"
    print "      <direction>in</direction>"
    print "      <quick>1</quick>"
    print "      <source>"
    print "        <network>opt10</network>"
    print "      </source>"
    print "      <destination>"
    print "        <network>opt10</network>"
    print "      </destination>"
    print "    </rule>"
    next
  }
  { print }
  ' "$CONFIG_FILE" > "$TMP_FILE" && mv "$TMP_FILE" "$CONFIG_FILE"
  echo "规则添加完成"
fi
  echo ""

# 启用 DoT 转发功能
log "$YELLOW" "启用DoT转发功能..."
if awk '
  BEGIN { in_forwarding=0; dot_enabled=0 }
  /<forwarding>/ { in_forwarding=1 }
  /<\/forwarding>/ { in_forwarding=0 }
  {
    if (in_forwarding && /<enabled>1<\/enabled>/) {
      dot_enabled=1
    }
  }
  END { exit !dot_enabled }
' "$CONFIG_FILE"; then
  log "$CYAN" "DoT转发已启用，忽略"
else
  awk '
  BEGIN { in_forwarding = 0 }
  /<forwarding>/ { in_forwarding = 1 }
  /<\/forwarding>/ { in_forwarding = 0 }
  {
    if (in_forwarding && /<enabled>0<\/enabled>/) {
      sub(/<enabled>0<\/enabled>/, "<enabled>1</enabled>")
    }
    print
  }
  ' "$CONFIG_FILE" > "$TMP_FILE" && mv "$TMP_FILE" "$CONFIG_FILE"
  echo "DoT转发已启用"
fi
echo ""

# 删除菜单缓存
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
rm -f /var/lib/php/tmp/opnsense_acl_cache.json

# 重新载入configd
log "$YELLOW" "重新载入configd..."
service configd restart > /dev/null 2>&1
echo ""

# 重启 Unbound DNS 服务
log "$YELLOW" "重启Unbound DNS..."
configctl unbound restart > /dev/null 2>&1
echo ""

# 重新载入防火墙规则
log "$YELLOW" "重新加载防火墙规则..."
configctl filter reload > /dev/null 2>&1
echo ""

# 完成提示
log "$GREEN" "安装完毕，请导航到VPN > Proxy Suite 进行配置。配置过程请参考配置教程。"
echo ""
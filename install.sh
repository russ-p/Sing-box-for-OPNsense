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

TUN_INTERFACE="opt10"
TUN_DEVICE="tun_3000"

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
chmod +x /usr/local/etc/sing-box/sub/sub.sh
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

export TUN_INTERFACE
export TUN_DEVICE

# 添加tun接口
log "$YELLOW" "添加 tun_3000 接口..."
if grep -q "<if>${TUN_DEVICE}</if>" "$CONFIG_FILE"; then
  log "$CYAN" "存在同名接口，忽略"
else
  awk '
  BEGIN { inserted = 0 }
  {
    print
    if ($0 ~ /<\/lo0>/ && inserted == 0) {
      print "    <" ENVIRON["TUN_INTERFACE"] ">"
      print "      <if>" ENVIRON["TUN_DEVICE"] "</if>"
      print "      <descr>TUN</descr>"
      print "      <enable>1</enable>"
      print "    </" ENVIRON["TUN_INTERFACE"] ">"
      inserted = 1
    }
  }
  ' "$CONFIG_FILE" > "$TMP_FILE" && mv "$TMP_FILE" "$CONFIG_FILE"
  echo "接口添加完成"
fi
echo ""

# 添加防火墙规则
log "$YELLOW" "添加 TUN 防火墙规则..."
if grep -q "5a73c3dc-69b1-4e15-89cb-b542aa2c1154" "$CONFIG_FILE"; then
  log "$CYAN" "存在同名规则，忽略"
else
  awk -v target="$TUN_INTERFACE" '
  BEGIN { inserted = 0 }
  {
    if ($0 ~ /<rules>/ && inserted == 0) {
      print
      print "          <rule uuid=\"5a73c3dc-69b1-4e15-89cb-b542aa2c1154\">"
      print "            <enabled>1</enabled>"
      print "            <statetype>keep</statetype>"
      print "            <state-policy/>"
      print "            <sequence>200</sequence>"
      print "            <action>pass</action>"
      print "            <quick>1</quick>"
      print "            <interfacenot>0</interfacenot>"
      print "            <interface>" target "</interface>"
      print "            <direction>in</direction>"
      print "            <ipprotocol>inet</ipprotocol>"
      print "            <protocol>any</protocol>"
      print "            <icmptype/>"
      print "            <icmp6type/>"
      print "            <source_net>" target "</source_net>"
      print "            <source_not>0</source_not>"
      print "            <source_port/>"
      print "            <destination_net>" target "</destination_net>"
      print "            <destination_not>0</destination_not>"
      print "            <destination_port/>"
      print "            <divert-to/>"
      print "            <gateway/>"
      print "            <replyto/>"
      print "            <disablereplyto>0</disablereplyto>"
      print "            <log>0</log>"
      print "            <allowopts>0</allowopts>"
      print "            <nosync>0</nosync>"
      print "            <nopfsync>0</nopfsync>"
      print "            <statetimeout/>"
      print "            <udp-first/>"
      print "            <udp-multiple/>"
      print "            <udp-single/>"
      print "            <max-src-nodes/>"
      print "            <max-src-states/>"
      print "            <max-src-conn/>"
      print "            <max/>"
      print "            <max-src-conn-rate/>"
      print "            <max-src-conn-rates/>"
      print "            <overload/>"
      print "            <adaptivestart/>"
      print "            <adaptiveend/>"
      print "            <prio/>"
      print "            <set-prio/>"
      print "            <set-prio-low/>"
      print "            <tag/>"
      print "            <tagged/>"
      print "            <tcpflags1/>"
      print "            <tcpflags2/>"
      print "            <tcpflags_any>0</tcpflags_any>"
      print "            <categories/>"
      print "            <sched/>"
      print "            <tos/>"
      print "            <shaper1/>"
      print "            <shaper2/>"
      print "            <description>Sing-Box TUN Allow All</description>"
      print "          </rule>"
      inserted = 1
      next
    }
    print
  }
  END {
    if (inserted == 0) exit 1
  }
  ' "$CONFIG_FILE" > "$TMP_FILE"

  if [ $? -eq 0 ] && [ -s "$TMP_FILE" ]; then
    mv "$TMP_FILE" "$CONFIG_FILE"
    log "$GREEN" "${TUN_INTERFACE} 防火墙规则添加完成"
  else
    rm -f "$TMP_FILE"
    log "$RED" "防火墙规则添加失败，请检查配置文件"
  fi
fi

# 更改 DNS 端口为 5355
sleep 1
log "$YELLOW" "更改 DNS 端口为 5355..."

DNS_STATE=$(awk '
BEGIN {
  in_unbound = 0
  in_general = 0
  has_5355 = 0
  has_other_port = 0
}
{
  if ($0 ~ /<unboundplus[^>]*>/ || $0 ~ /<unbound[^>]*>/) in_unbound = 1
  if (in_unbound && $0 ~ /<general>/) in_general = 1

  if (in_unbound && in_general && $0 ~ /<port>5355<\/port>/) has_5355 = 1
  if (in_unbound && in_general && $0 ~ /<port>[0-9]+<\/port>/ && $0 !~ /<port>5355<\/port>/) has_other_port = 1

  if (in_unbound && $0 ~ /<\/general>/) in_general = 0
  if ($0 ~ /<\/unboundplus>/ || $0 ~ /<\/unbound>/) {
    in_unbound = 0
    in_general = 0
  }
}
END {
  if (has_5355) {
    print "already_ok"
  } else if (has_other_port) {
    print "need_replace"
  } else {
    print "need_insert"
  }
}
' "$CONFIG_FILE")

if [ "$DNS_STATE" = "already_ok" ]; then
  log "$CYAN" "DNS 端口已经为 5355，跳过"
else
  awk '
  BEGIN {
    in_unbound = 0
    in_general = 0
    port_handled = 0
  }
  {
    if ($0 ~ /<unboundplus[^>]*>/ || $0 ~ /<unbound[^>]*>/) {
      in_unbound = 1
    }

    if (in_unbound && $0 ~ /<general>/) {
      in_general = 1
      print
      next
    }

    if (in_unbound && in_general && $0 ~ /<\/general>/) {
      if (port_handled == 0) {
        print "        <port>5355</port>"
        port_handled = 1
      }
      in_general = 0
      print
      next
    }

    if (in_unbound && in_general && $0 ~ /<port>[0-9]+<\/port>/ && port_handled == 0) {
      sub(/<port>[0-9]+<\/port>/, "<port>5355</port>")
      port_handled = 1
      print
      next
    }

    print

    if ($0 ~ /<\/unboundplus>/ || $0 ~ /<\/unbound>/) {
      in_unbound = 0
      in_general = 0
    }
  }
  END {
    if (port_handled == 0) exit 1
  }
  ' "$CONFIG_FILE" > "$TMP_FILE"

  if [ $? -eq 0 ] && [ -s "$TMP_FILE" ]; then
    mv "$TMP_FILE" "$CONFIG_FILE"
    log "$GREEN" "DNS 端口已设置为 5355"
  else
    rm -f "$TMP_FILE"
    log "$RED" "修改 DNS 端口失败，请检查配置文件"
  fi
fi

echo ""

# 删除菜单缓存
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
rm -f /var/lib/php/tmp/opnsense_acl_cache.json

# 重新载入configd
log "$YELLOW" "重新载入configd..."
service configd restart > /dev/null 2>&1
echo ""

# 重新载入接口与防火墙规则
log "$YELLOW" "重新加载接口与防火墙规则..."
configctl filter reload > /dev/null 2>&1
configctl unbound restart > /dev/null 2>&1
configctl template reload OPNsense/Unbound > /dev/null 2>&1
echo ""

# 完成提示
log "$GREEN" "安装完毕，请导航到VPN > Proxy Suite 进行配置。配置过程请参考配置教程。"
echo ""
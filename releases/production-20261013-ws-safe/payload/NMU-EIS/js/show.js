Ext.onReady(function () {
  Ext.xcollapsed = 0;
  Ext.TitleCollapsed = "";
  if (Ext.getInfo.serverName == "uat-eis.vajira.ac.th" && Ext.Annouce.TOPBAR_EIS == "") {
    Ext.TitleCollapsed = '<span style="color:red; font-size: 14px; font-weight: bold;">' + "&nbsp;&nbsp;&nbsp; สำหรับการทดสอบระบบ (UAT)" + "</span>";
  } else {
    Ext.TitleCollapsed = Ext.Annouce.TOPBAR_EIS;
  }

  Ext.QuickTips.init();
  Ext.showLoadingMask();

  //     if (typeof (Storage) !== "undefined") {
  //         if (localStorage.counterrefresh) {
  //             localStorage.counterrefresh = Number(localStorage.counterrefresh) + 1;
  //         } else {
  //             localStorage.counterrefresh = 1;
  //         }
  //         alert(localStorage.counterrefresh);
  //     } else {
  //         alert("No web storage support");
  //     }
  var root = new Ext.tree.AsyncTreeNode();
  var loader = new Ext.tree.TreeLoader({
    dataUrl: "api/userMenu/userTree.php",
    requestMethod: "get",
    baseParams: { url: window.location.href, parent_id: localStorage.getItem("parentId") },
  });

  var TreePanel1 = new Ext.tree.TreePanel({
    title: "เมนูย่อย",
    border: false,
    id: "TreePanel1ID",
    autoScroll: true,
    rootVisible: false, // show Root Node
    lines: false,
    singleExpand: true,
    useArrows: true,
    loader: loader,
    root: root,
    listeners: {
      click: function (node, e) {
        if (node.isLeaf()) {
        } else if (node.isExpanded()) {
          node.collapse();
        } else {
          node.expand();
        }
      },
    },
  });

  let getBrowsers = function () {
    var nAgt = navigator.userAgent;
    var browserName = navigator.appName;
    var fullVersion = "" + parseFloat(navigator.appVersion);
    var majorVersion = parseInt(navigator.appVersion, 10);
    var nameOffset, verOffset, ix;

    // In Opera, the true version is after "Opera" or after "Version"
    if ((verOffset = nAgt.indexOf("Opera")) != -1) {
      browserName = "Opera";
      fullVersion = nAgt.substring(verOffset + 6);
      if ((verOffset = nAgt.indexOf("Version")) != -1) fullVersion = nAgt.substring(verOffset + 8);
    }
    // In MSIE, the true version is after "MSIE" in userAgent
    else if ((verOffset = nAgt.indexOf("MSIE")) != -1) {
      browserName = "Microsoft Internet Explorer";
      fullVersion = nAgt.substring(verOffset + 5);
    }
    // In Chrome, the true version is after "Chrome"
    else if ((verOffset = nAgt.indexOf("Chrome")) != -1) {
      browserName = "Chrome";
      fullVersion = nAgt.substring(verOffset + 7);
    }
    // In Safari, the true version is after "Safari" or after "Version"
    else if ((verOffset = nAgt.indexOf("Safari")) != -1) {
      browserName = "Safari";
      fullVersion = nAgt.substring(verOffset + 7);
      if ((verOffset = nAgt.indexOf("Version")) != -1) fullVersion = nAgt.substring(verOffset + 8);
    }
    // In Firefox, the true version is after "Firefox"
    else if ((verOffset = nAgt.indexOf("Firefox")) != -1) {
      browserName = "Firefox";
      fullVersion = nAgt.substring(verOffset + 8);
    }
    // In most other browsers, "name/version" is at the end of userAgent
    else if ((nameOffset = nAgt.lastIndexOf(" ") + 1) < (verOffset = nAgt.lastIndexOf("/"))) {
      browserName = nAgt.substring(nameOffset, verOffset);
      fullVersion = nAgt.substring(verOffset + 1);
      if (browserName.toLowerCase() == browserName.toUpperCase()) {
        browserName = navigator.appName;
      }
    }
    // trim the fullVersion string at semicolon/space if present
    if ((ix = fullVersion.indexOf(";")) != -1) fullVersion = fullVersion.substring(0, ix);
    if ((ix = fullVersion.indexOf(" ")) != -1) fullVersion = fullVersion.substring(0, ix);

    majorVersion = parseInt("" + fullVersion, 10);
    if (isNaN(majorVersion)) {
      fullVersion = "" + parseFloat(navigator.appVersion);
      majorVersion = parseInt(navigator.appVersion, 10);
    }

    return browserName + "  = " + fullVersion + ",Major version = " + majorVersion + ",navigator.appName = " + navigator.appName + ",navigator.userAgent = " + navigator.userAgent;
  };

  TreePanel1.on("click", function (n) {
    var sn = this.selModel.selNode || {}; // selNode is null on initial selection
    if (n.leaf && n.id != sn.id) {
      // ignore clicks on folders and currently selected node

      var url = n.id.replace(new RegExp("-", "g"), "/");
      var fullURL = url;
      var fullNameSplit = fullURL.split("?");
      var uri = fullNameSplit[0];
      var method = Ext.isEmpty(fullNameSplit[1]) ? "" : fullNameSplit[1];

      Ext.page = Ext.apply({ uri: uri, method: method });
      Ext.History.add(uri);

      if (n.leaf) {
        var accessManu = { menuID: n.id, menuTxt: n.text, browser: getBrowsers() };
        var accessinfo = Ext.apply(Ext.getInfo, accessManu);

        Ext.Ajax.request({
          url: "./access/logAccess.php",
          method: "POST",
          params: accessinfo,
          success: function (response) {
            //Ext.get('userInfo').update(response.responseText);
            var obj = $.parseJSON(response.responseText);
            if (obj.user_id != Ext.getInfo.sysUserId) {
              location.href = "https://" + location.host + "/nmu_eis/";
            }
          },
        });
        Ext.getCmp("content-panel").update('<iframe src="' + Ext.page.uri + ".php?" + Ext.page.method + '" frameborder="0" width="100%" height="100%"></iframe>');
        //        Ext.getCmp("content-panel").update('<iframe src="' + url + '.php" frameborder="0" width="100%" height="100%"></iframe>');
      }
    }
  });

  TreePanel1.on("contextmenu", function (n) {
    if (n.leaf) {
      let favoriteMenu = Ext.util.JSON.decode(localStorage.getItem("favoriteMenu"));
      let chk = undefined;
      if (favoriteMenu != "") {
        chk = favoriteMenu.find((success) => success.id === n.id);
      }
      let menu = new Ext.menu.Menu({
        items: [
          {
            text: "เพิ่มรายการโปรด",
            iconCls: "icon-tag-yellow",
            checked: chk != undefined ? true : false,
            checkHandler: function (item, checked) {
              let json = [];
              let arrFavorite = [];
              if (checked === false) {
                arrFavorite[n.id] = n.id;
              }

              if (favoriteMenu != "") {
                favoriteMenu.forEach((obj) => {
                  if (arrFavorite[obj.id] != obj.id && obj.id != undefined) {
                    json.push({ id: obj.id, text: obj.text });
                  }
                  arrFavorite[obj.id] = obj.id;
                });
              }

              if (checked) {
                if (arrFavorite[n.id] != n.id) {
                  json.unshift({ id: n.id, text: n.text });
                }
              }
              localStorage.setItem("favoriteMenu", JSON.stringify(json));
              favorite();
            },
            listeners: {
              afterrender: function (button) {
                button.el.on("contextmenu", function (e) {
                  e.stopEvent();
                });
              },
            },
          },
        ],
      });
      Ext.EventObject.stopEvent();
      menu.showAt(Ext.EventObject.getXY());
    }
  });

  let TreePanel2 = new Ext.Panel({
    id: "TreePanel2ID",
    border: false,
    title: "User",
    bodyStyle: "padding: 2px;",
    autoScroll: true,
    html:
      "<style>" +
      ".div-online { font-family: 'Krub'; }" +
      ".div-online ul li { border-bottom: 1px solid #d8d8d8; padding: 6px 8px; height: 34px; }" +
      ".div-online ul li img { width: 30px; top: 2px; position: relative; } " +
      ".sp-online { background: #7de26a; }" +
      ".sp-offline { background: #FF7C81; }" +
      ".sp-status { position: absolute; width: 10px; height: 10px; border-radius: 20px; top: 7px; left: 36px; }" +
      ".sp-username { color: #353535; font-size: 12px; font-weight: bold; margin-top: 4px; position: absolute; margin-left: 22px; }" +
      ".sp-costname { font-size: 10px; margin-top: 22px; position: absolute; margin-left: 8px; }" +
      ".sp-position { float: right; position: relative; top: 2px; font-size: 11px; background: #94989a; padding: 0px 0px; width: 50px; color: #fff; border-radius: 30px; text-align: center; }" +
      "</style>" +
      "<div class='div-online'>" +
      "<div style='text-align: center;'><img src='images/sky-loading.svg'><div>Loadding...</div></div>" +
      "</div>",
    listeners: {
      collapse: function () {
        Ext.getCmp("TreePanel1ID").expand();
      },
      expand: function () {
        Ext.getCmp("TreePanel3ID").collapse();
        Ext.getCmp("TreePanel1ID").collapse();
      },
    },
  });

  let TreePanel3 = new Ext.Panel({
    id: "TreePanel3ID",
    border: false,
    title: "แจ้งเตือน",
    bodyStyle: "padding: 2px;",
    autoScroll: true,
    html:
      "<style>" +
      ".div-notifyUser { font-family: 'Krub'; }" +
      ".div-notifyUser ul li { border-bottom: 1px solid #d8d8d8; padding: 6px 8px; height: 72px; }" +
      ".div-notifyUser ul li img { width: 30px; top: 2px; position: relative; } " +
      // ".nt-online { background: #7de26a; }" +
      ".nt-new { background: #FF7C81; }" +
      ".nt-status { position: absolute; width: 10px; height: 10px; border-radius: 20px; top: 3px; left: -15px; }" +
      ".nt-title{ color: #353535; font-size: 12px; font-weight: bold; margin-top: 4px; position: absolute; margin-left: 18px; }" +
      ".nt-msg{ font-size: 10px; margin-top: 22px; position: absolute; margin-left: 32px; }" +
      ".nt-datetime { float: right; position: relative; top: 0px; font-size: 11px; background: #8A8A8A; padding: 0px 0px; width: 57px; color: #fff; border-radius: 30px; text-align: center; }" +
      "</style>" +
      "<div class='div-notifyUser'>" +
      "<div style='text-align: center;'><img src='images/sky-loading.svg'><div>Loadding...</div></div>" +
      "</div>",
    listeners: {
      collapse: function () {
        Ext.getCmp("TreePanel1ID").expand();
      },
      expand: function () {
        Ext.getCmp("TreePanel1ID").collapse();
        if (Ext.socket_status == 1) {
          var datetime = DateTimeNow();
          let user_data_socket = {
            mode: "NOTIFYREADTIME",
            sysUserId: Ext.getInfo.sysUserId,
            date: datetime.split(" ")[0],
            time: datetime.split(" ")[1].slice(0, 5),
          };
          // Reuse the notification channel; opening the panel must not create another connection.
          let socket = window.NMUNotificationSocket;
          if (socket && socket.connected) socket.emit("NotifyReadtime", user_data_socket);
        }
      },
    },
  });

  // WEST
  let widthWest = isNaN(parseFloat(localStorage.getItem("widthWest")) == true) || parseFloat(localStorage.getItem("widthWest")) > 1200 ? 400 : parseFloat(localStorage.getItem("widthWest"));

  let collapsed = localStorage.getItem("collapsed") == null || localStorage.getItem("collapsed") == "1" ? false : true;
  let west = new Ext.Panel({
    id: "west",
    region: "west",
    title: "&nbsp;",
    layout: "accordion",
    //        collapseMode: 'mini',
    collapsible: true,
    autoScroll: true,
    split: true,
    collapsed: collapsed,
    width: widthWest,
    activeItem: 0,
    listeners: {
      afterrender: async function () {
        await $.ajax({
          url: "api/userMenu/List_parentTree.php",
          data: {
            type: "GET_PARENT_MENU",
            parent_id: localStorage.getItem("parentId") ? localStorage.getItem("parentId") : "",
          },
          success: function (result) {
            let obj = $.parseJSON(result);
            if (obj.success == true) {
              Ext.getCmp("txt-system").setIconClass("icon-report-start");
              Ext.getCmp("txt-system").setText("<span style='font-weight: bold; font-size: 12px; margin: 0px 1px;'>" + obj.c_name + "</span>");
              localStorage.setItem("parentId", obj.parent_id);

              $.each(obj.data, function (index, v) {
                Ext.getCmp("menu-system").add({
                  text: v.c_name,
                  iconCls: "icon-report",
                  handler: function () {
                    localStorage.setItem("parentId", v.id);
                    location.reload();
                  },
                });
              });
              Ext.getCmp("menu-system").doLayout();

              Ext.getCmp("west").add(TreePanel1);
              if (Ext.getInfo.i_type_user == 2) {
                Ext.getCmp("west").add(TreePanel2);
              }
              var UserNotify = [1, 28, 35, 46]; //ถ้าอยากให้เห็นทุกคน คอมเม้นด้วย
              if (UserNotify.includes(Ext.getInfo.sysUserId)) {
                //นี่ด้วย
                Ext.getCmp("west").add(TreePanel3);
              } //และนี่
              Ext.getCmp("west").update();
              Ext.getCmp("west").doLayout();
            }
          },
        });
      },
      collapse: function () {
        localStorage.setItem("collapsed", "0");
      },
      expand: function () {
        localStorage.setItem("collapsed", "1");
      },
      resize: function () {
        localStorage.setItem("widthWest", this.getWidth());
      },
    },
    bbar: {
      hidden: Number(Ext.getInfo.i_type_user) !== 2,
      items: [{
        text: "ผู้ใช้งานออนไลน์ (Admin)",
        handler: function () {
          Ext.page = { uri: "access/user_online", method: "" };
          Ext.History.add("access/user_online");
          Ext.getCmp("content-panel").update('<iframe src="access/user_online.php" title="ผู้ใช้งานออนไลน์" frameborder="0" width="100%" height="100%"></iframe>');
        },
      }],
    },
    tbar: [
      {
        id: "txt-system",
        text: "<img style='animation-name: spin; animation-duration: 500ms;animation-iteration-count: infinite;animation-timing-function: linear;' src='./images/icons/hourglass.png' />&nbsp;&nbsp;<span style='position: relative; top: -4px; left: 0px;'>Loading...</span>",
        menu: new Ext.menu.Menu({ id: "menu-system" }),
      },
    ],
  });

  let collapsedNorth = localStorage.getItem("collapsedNorth") == null || localStorage.getItem("collapsedNorth") == "1" ? false : true;
  let north = new Ext.Panel({
    id: "north",
    region: "north",
    split: false,
    collapsible: true,
    height: 125,
    border: false,
    collapsed: collapsedNorth,
    title: Ext.TitleCollapsed,
    items: [
      new Ext.Panel({
        border: true,
        html: '<div id="headerx"><div id="header" align="right"><div id="divID" style="margin:0px 0px 0px 0px;"></div></div></div><a href="/nmu_permission/"><div class="clickable-box" ></div></a>',
      }),
      new Ext.Panel({
        id: "favoriteMenu",
        border: false,
        tbar: [],
      }),
    ],
    listeners: {
      collapse: function () {
        if (Ext.TitleCollapsed != "" && Ext.xcollapsed == 0) {
          Ext.xcollapsed = 1;
          const div = document.createElement("div");
          div.innerHTML = Ext.TitleCollapsed;
          document.getElementById("north-xcollapsed").appendChild(div);
          localStorage.setItem("collapsedNorth", "0");
        }
      },
      expand: function () {
        localStorage.setItem("collapsedNorth", "1");
      },
    },
  });

  // CENTER
  let center = {
    region: "center",
    id: "content-panel",
    //		collapsible: true,
    listeners: {
      afterrender: function () {
        Ext.getCmp("content-panel").update('<iframe src="welcome.php" frameborder="0" width="100%" height="100%"></iframe>');
      },
    },
  };

  // SOUTH
  let south = {
    region: "south",
    // title: "Develop by NKL International Company Limited. Version 16.1.1", //ชื่อเก่า
    // title: "Develop by CHULA UNISEARCH & NKL International Co, Ltd.",
    title: "Develop by Chula Unisearch, Chulalongkorn University",

    id: "footer",
  };

  // api access
  Ext.Ajax.request({
    url: "./access/info.php",
    success: function (response) {
      // RENDER
      Ext.WebApp = new Ext.Viewport({
        layout: "border",
        items: [north, west, center, south],
      });
      Ext.get("divID").update('<div id="logout">' + '<div id="userInfo" style="margin-top:0px; float:left;"></div>' + "</div>");
      Ext.get("userInfo").update(response.responseText);
      favorite();
      // alertSystem();
      var url = window.location.href;
      url = url.split("#");
      console.log(url);

      if (url[1]) {
        Ext.getCmp("content-panel").update('<iframe src="' + url[1] + '.php" frameborder="0" width="100%" height="100%"></iframe>');
      }
    },
  });

  const favorite = function () {
    let json = Ext.util.JSON.decode(localStorage.getItem("favoriteMenu"));
    Ext.getCmp("favoriteMenu").getTopToolbar().removeAll();
    if (json != "") {
      json.forEach(function (element, index) {
        let menu = new Ext.menu.Menu({
          items: [
            {
              text: "ลบรายการโปรด",
              iconCls: "icon-delete",
              listeners: {
                afterrender: function (button) {
                  button.el.on("contextmenu", function (e) {
                    e.stopEvent();
                  });
                },
                click: function (obj, eOpts) {
                  let favoriteMenu = Ext.util.JSON.decode(localStorage.getItem("favoriteMenu"));
                  let json = [];
                  favoriteMenu.forEach((obj) => {
                    if (obj.id !== element.id) {
                      json.push({ id: obj.id, text: obj.text });
                    }
                  });
                  localStorage.setItem("favoriteMenu", JSON.stringify(json));
                  favorite();
                },
              },
            },
          ],
        });

        Ext.getCmp("favoriteMenu")
          .getTopToolbar()
          .add(
            index != 0 ? "-" : "",
            new Ext.Button({
              id: element.id,
              iconCls: "icon-tag-yellow",
              text: element.text,
              listeners: {
                afterrender: function (button) {
                  button.el.on("contextmenu", function (e) {
                    Ext.EventObject.stopEvent();
                    menu.showAt(Ext.EventObject.getXY());
                    e.stopEvent();
                  });
                },
                click: function (obj, eOpts) {
                  let url = element.id.replace(new RegExp("-", "g"), "/");
                  Ext.History.add(url);
                  Ext.getCmp("content-panel").update('<iframe src="' + url + '.php" frameborder="0" width="100%" height="100%"></iframe>');
                },
              },
            })
          );
        Ext.getCmp("favoriteMenu").doLayout();
      });
    }
  };
});

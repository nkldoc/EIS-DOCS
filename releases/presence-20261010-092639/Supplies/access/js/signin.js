Ext.onReady(function() {
	Ext.submitForm = function(){
		var form = Ext.getCmp("login-form").getForm();
		if (form.isValid())
		{ 
			form.submit({
				waitMsg:'กรุณารอซักครู่...',
				success : function(form, response) {
					var res = new Object();
					res = Ext.util.JSON.decode(response.response.responseText);
					console.log(res.success);
					if (res.duplicate_login) { location.href = res.confirm_url; return; }
					if(res.success == 'Success'){
						location.href = "../index.php";
					}else{
						Ext.MessageBox.alert('Message',res.msg);
					}
				},
				failure:  function(form, response) {
					if (response.result && response.result.duplicate_login) { location.href = response.result.confirm_url; return; }
					switch (response.failureType) {
						case Ext.form.Action.CLIENT_INVALID:
							Ext.Msg.alert('Failure', 'Form fields may not be submitted with invalid values');
							break;
						case Ext.form.Action.CONNECT_FAILURE:
							Ext.Msg.alert('Failure', 'Ajax communication failed');
							break;
						case Ext.form.Action.SERVER_INVALID:
						   Ext.Msg.alert('Failure', response.result.msg);
					}
				}
			});
		} else {
			Ext.Msg.alert('Failure', "กรุณากรอกข้อมูลให้ครบถ้วน !!");
		}
	} 
	/* Listeners Enter	 */
	Ext.enterSubmit = {
			specialkey: function(f,e){
				if(e.getKey() == e.ENTER){ 
					 Ext.submitForm();
				}
			}
	};	
 
    var formLogin = new Ext.form.FormPanel({
		id:'login-form',
		url:'login.php',
        baseCls: 'x-plain',
        layout:'absolute',
        defaultType: 'textfield',
		items: [
			{ x: 0, y: 5, xtype:'label', text: 'รหัสผู้ใช้:' },
			{ x: 60, y: 0,
				name: 'userf',
				anchor:'100%',
				minLength: 4,
				msgTarget: 'under',
				listeners: {
					specialkey: function(f,e){
						if(e.getKey() == e.ENTER){ 
							 Ext.submitForm();
						}
					},
					afterrender: function(field) {
						field.focus(false, 100);
					},
			    }
			},
			{ x: 0, y: 35, xtype:'label', text: 'รหัสผ่าน:' },
			{ x: 60, y: 30,
				inputType: 'password',
				name: 'passwordf',
				anchor: '100%',
				minLength: 4,
				msgTarget: 'under',
				listeners:Ext.enterSubmit,
			}
		],
		defaults: { allowBlank: false }
    });

	var window = new Ext.Window({
					title: 'Login',
					y:150,
					width: 300,
					height:150,
					layout: 'fit',
					closeAction: 'hide',
					closable: false,
					plain:true,
					bodyStyle:'padding:10px;',
					buttonAlign:'center',
					items: formLogin,
					buttons: [
						{
							text : "เข้าสู่ระบบ",
							formBind: false,
							handler : function() {
								Ext.submitForm();
								
							}
						}
					]
				}).show();
 
});

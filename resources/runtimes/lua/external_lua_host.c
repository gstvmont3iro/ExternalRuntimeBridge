
/*
 * server Lua 5.4 host
 * The host deliberately exposes a small RPC API instead of PHP objects.
 * Lua standard libraries which can access the host filesystem/processes are
 * disabled after loading the standard library set.
 *
 * Protocol (one line per message, fields URL-escaped with %HH):
 * PHP -> host: INVOKE <callback-id> <event-name> key value ...
 * PHP -> host: RESULT <ok 0/1> <value>
 * host -> PHP: READY
 * host -> PHP: CALL <method> arg...
 * host -> PHP: REGISTER_EVENT <name> <callback-id>
 * host -> PHP: REGISTER_COMMAND <name> <callback-id> description usage permission aliases
 * host -> PHP: REGISTER_TIMER <callback-id> mode delay period
 * host -> PHP: RETURN <callback-id> value
 * host -> PHP: CALLBACK_ERROR <callback-id> message
 * host -> PHP: ERROR <stage> message
 *
 * Lua 5.4 public ABI declarations are reproduced here so the project can
 * build the small host even when Lua development headers are not installed.
 * The shipped binary is linked against Lua 5.4.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdarg.h>
#include <ctype.h>
#include <errno.h>
#include <signal.h>
#include <sys/select.h>
#include <unistd.h>
#include <errno.h>

typedef struct lua_State lua_State;
typedef int (*lua_CFunction)(lua_State *L);
typedef long long lua_Integer;
typedef double lua_Number;
typedef int lua_KContext;
typedef int (*lua_KFunction)(lua_State*, int, lua_KContext);
typedef unsigned char lu_byte;

#define LUA_OK 0
#define LUA_MULTRET (-1)
#define LUA_REGISTRYINDEX (-1001000)
#define LUA_NOREF (-2)
#define LUA_REFNIL (-1)
#define LUA_TNONE (-1)
#define LUA_TNIL 0
#define LUA_TBOOLEAN 1
#define LUA_TLIGHTUSERDATA 2
#define LUA_TNUMBER 3
#define LUA_TSTRING 4
#define LUA_TTABLE 5
#define LUA_TFUNCTION 6
#define lua_upvalueindex(i) (LUA_REGISTRYINDEX - (i))
#define lua_pushcfunction(L,f) lua_pushcclosure(L,(f),0)

extern lua_State *luaL_newstate(void);
extern void lua_close(lua_State *L);
extern void luaL_openlibs(lua_State *L);
extern int luaL_loadfilex(lua_State *L, const char *filename, const char *mode);
extern int luaL_loadstring(lua_State *L, const char *s);
extern int lua_pcallk(lua_State *L, int nargs, int nresults, int errfunc, lua_KContext ctx, lua_KFunction k);
extern int lua_gettop(lua_State *L);
extern void lua_settop(lua_State *L, int idx);
extern void lua_rotate(lua_State *L, int idx, int n);
extern int lua_type(lua_State *L, int idx);
extern const char *lua_tolstring(lua_State *L, int idx, size_t *len);
extern int lua_toboolean(lua_State *L, int idx);
extern lua_Integer lua_tointegerx(lua_State *L, int idx, int *isnum);
extern lua_Number lua_tonumberx(lua_State *L, int idx, int *isnum);
extern void lua_pushnil(lua_State *L);
extern void lua_pushboolean(lua_State *L, int b);
extern void lua_pushinteger(lua_State *L, lua_Integer n);
extern void lua_pushnumber(lua_State *L, lua_Number n);
extern const char *lua_pushstring(lua_State *L, const char *s);
extern void lua_pushvalue(lua_State *L, int idx);
extern void lua_pushcclosure(lua_State *L, lua_CFunction fn, int n);
extern void lua_createtable(lua_State *L, int narr, int nrec);
extern void lua_setfield(lua_State *L, int idx, const char *k);
extern int lua_getfield(lua_State *L, int idx, const char *k);
extern void lua_getglobal(lua_State *L, const char *name);
extern void lua_setglobal(lua_State *L, const char *name);
extern int lua_next(lua_State *L, int idx);
extern void lua_setmetatable(lua_State *L, int idx);
extern void lua_seti(lua_State *L, int idx, lua_Integer n);
extern int luaL_ref(lua_State *L, int t);
extern void luaL_unref(lua_State *L, int t, int ref);
extern void lua_rawgeti(lua_State *L, int idx, lua_Integer n);
extern void luaL_traceback(lua_State *L, lua_State *L1, const char *msg, int level);
extern lua_Integer luaL_optinteger(lua_State *L, int arg, lua_Integer d);
extern const char *luaL_optlstring(lua_State *L, int arg, const char *def, size_t *l);
extern void luaL_error(lua_State *L, const char *fmt, ...);

typedef struct Callback {
    int id;
    int ref;
    char *kind;
    struct Callback *next;
} Callback;

static Callback *callbacks = NULL;
static int next_callback_id = 1;
static long bridge_timeout_ms = 1000;

static void die(const char *msg) {
    fprintf(stderr, "external-lua-host: %s\n", msg);
    exit(2);
}

static char hex(int v) { return (char)(v < 10 ? '0' + v : 'A' + (v - 10)); }

static char *enc(const char *s) {
    size_t i, n = strlen(s), outn = 0;
    char *out;
    for (i=0; i<n; ++i) {
        unsigned char c=(unsigned char)s[i];
        if (isalnum(c) || c=='-' || c=='_' || c=='.' || c=='~') outn++;
        else outn += 3;
    }
    out=(char*)malloc(outn+1);
    if(!out) die("out of memory");
    outn=0;
    for(i=0;i<n;++i){
        unsigned char c=(unsigned char)s[i];
        if (isalnum(c) || c=='-' || c=='_' || c=='.' || c=='~') out[outn++]=(char)c;
        else { out[outn++]='%'; out[outn++]=hex(c>>4); out[outn++]=hex(c&15); }
    }
    out[outn]=0;
    return out;
}

static int hval(char c) {
    if(c>='0'&&c<='9') return c-'0';
    if(c>='A'&&c<='F') return c-'A'+10;
    if(c>='a'&&c<='f') return c-'a'+10;
    return -1;
}

static char *dec(const char *s) {
    size_t i,n=strlen(s),outn=0;
    char *out=(char*)malloc(n+1);
    if(!out) die("out of memory");
    for(i=0;i<n;++i){
        if(s[i]=='%' && i+2<n && hval(s[i+1])>=0 && hval(s[i+2])>=0){
            out[outn++]=(char)((hval(s[i+1])<<4)|hval(s[i+2]));
            i+=2;
        }else out[outn++]=s[i];
    }
    out[outn]=0;
    return out;
}

static void send_line1(const char *a) { printf("%s\n", a); fflush(stdout); }

static void send_line(const char *tag, int argc, const char **argv) {
    int i;
    printf("%s", tag);
    for(i=0;i<argc;++i){
        char *e=enc(argv[i]?argv[i]:"");
        printf("\t%s", e);
        free(e);
    }
    printf("\n"); fflush(stdout);
}

static Callback *cb_find(int id) {
    Callback *c=callbacks;
    while(c){ if(c->id==id) return c; c=c->next; }
    return NULL;
}

static int cb_add(lua_State *L, int ref, const char *kind) {
    Callback *c=(Callback*)calloc(1,sizeof(Callback));
    if(!c) die("out of memory");
    c->id=next_callback_id++;
    c->ref=ref;
    c->kind=strdup(kind);
    c->next=callbacks;
    callbacks=c;
    return c->id;
}

static void cb_remove(lua_State *L, int id) {
    Callback **pp=&callbacks, *c;
    while((c=*pp)!=NULL){
        if(c->id==id){
            *pp=c->next;
            luaL_unref(L, LUA_REGISTRYINDEX, c->ref);
            free(c->kind); free(c);
            return;
        }
        pp=&c->next;
    }
}

static int wait_stdin_data(long timeout_ms) {
    fd_set rfds;
    struct timeval tv;
    int fd = fileno(stdin);
    long sec = timeout_ms / 1000;
    long usec = (timeout_ms % 1000) * 1000;
    FD_ZERO(&rfds);
    FD_SET(fd, &rfds);
    tv.tv_sec = sec;
    tv.tv_usec = usec;
    for(;;){
        int r = select(fd + 1, &rfds, NULL, NULL, &tv);
        if(r > 0) return 1;
        if(r == 0) return 0;
        if(errno == EINTR) continue;
        return -1;
    }
}

static char *wait_result(int *ok) {
    static char line[65536];
    char *save=NULL, *tag, *a, *b;
    int wait = wait_stdin_data(bridge_timeout_ms);
    if(wait <= 0){
        *ok=0;
        return strdup(wait == 0 ? "bridge timeout" : "bridge wait failed");
    }
    if(!fgets(line,sizeof(line),stdin)){ *ok=0; return strdup("bridge disconnected"); }
    tag=strtok_r(line,"\t\r\n",&save);
    a=strtok_r(NULL,"\t\r\n",&save);
    b=strtok_r(NULL,"\t\r\n",&save);
    if(!tag || strcmp(tag,"RESULT")!=0){ *ok=0; return strdup("unexpected response from server"); }
    *ok = a && strcmp(a,"1")==0;
    if(!b) return strdup("");
    return dec(b);
}

static char *rpc(int argc, const char **argv) {
    int i, ok=0;
    char *res;
    send_line("CALL", argc, argv);
    res=wait_result(&ok);
    if(!ok) return res;
    return res;
}

static char *arg_string(lua_State *L, int idx) {
    const char *s=lua_tolstring(L,idx,NULL);
    return strdup(s?s:"");
}

static int get_self_id(lua_State *L) {
    int isnum=0;
    lua_getfield(L,1,"__player_id");
    lua_Integer id=lua_tointegerx(L,-1,&isnum);
    lua_settop(L,-2);
    return isnum?(int)id:0;
}

static void push_player(lua_State *L, int id) {
    lua_createtable(L,0,8);
    lua_pushinteger(L,id); lua_setfield(L,-2,"__player_id");
}

/* Forward declarations for closures */
static int player_send_message(lua_State *L);
static int player_get_name(lua_State *L);
static int player_get_health(lua_State *L);
static int player_set_health(lua_State *L);
static int player_get_position(lua_State *L);
static int player_teleport(lua_State *L);
static int player_has_permission(lua_State *L);
static int event_set_cancelled(lua_State *L);
static int world_get_name(lua_State *L);
static int world_get_players(lua_State *L);
static int world_get_block(lua_State *L);
static int world_set_block(lua_State *L);
static int entity_get_name(lua_State *L);
static int entity_get_position(lua_State *L);
static int config_set(lua_State *L);
static int config_save(lua_State *L);
static int config_reload(lua_State *L);
static int server_get_world(lua_State *L);
static int server_get_default_world(lua_State *L);
static int server_get_data_path(lua_State *L);
static int plugin_get_name(lua_State *L);
static int plugin_get_data_folder(lua_State *L);
static int sender_send_message(lua_State *L);
static int sender_get_name(lua_State *L);
static int sender_has_permission(lua_State *L);

static void add_player_method(lua_State *L, const char *name, lua_CFunction fn, int id) {
    lua_pushinteger(L,id); lua_pushcclosure(L,fn,1); lua_setfield(L,-2,name);
}
static void make_player(lua_State *L, int id) {
    lua_createtable(L,0,8);
    lua_pushinteger(L,id); lua_setfield(L,-2,"__player_id");
    add_player_method(L,"sendMessage",player_send_message,id);
    add_player_method(L,"getName",player_get_name,id);
    add_player_method(L,"getHealth",player_get_health,id);
    add_player_method(L,"setHealth",player_set_health,id);
    add_player_method(L,"getPosition",player_get_position,id);
    add_player_method(L,"teleport",player_teleport,id);
    add_player_method(L,"hasPermission",player_has_permission,id);
}
static int up_id(lua_State *L) {
    int isnum=0; lua_Integer id=lua_tointegerx(L,lua_upvalueindex(1),&isnum);
    return isnum?(int)id:0;
}
static int player_send_message(lua_State *L) {
    char *msg=arg_string(L,2), idbuf[32], *res;
    const char *args[3];
    snprintf(idbuf,sizeof(idbuf),"%d",up_id(L));
    args[0]="player.sendMessage"; args[1]=idbuf; args[2]=msg;
    res=rpc(3,args); free(msg);
    if(res && strcmp(res,"ERR")==0){ free(res); return 0; }
    free(res); lua_pushboolean(L,1); return 1;
}
static int player_get_name(lua_State *L) {
    char idbuf[32], *res; const char *args[2];
    snprintf(idbuf,sizeof(idbuf),"%d",up_id(L)); args[0]="player.getName"; args[1]=idbuf;
    res=rpc(2,args); lua_pushstring(L,res); free(res); return 1;
}
static int player_get_health(lua_State *L) {
    char idbuf[32], *res; const char *args[2];
    snprintf(idbuf,sizeof(idbuf),"%d",up_id(L)); args[0]="player.getHealth"; args[1]=idbuf;
    res=rpc(2,args); lua_pushnumber(L,atof(res)); free(res); return 1;
}
static int player_set_health(lua_State *L) {
    char idbuf[32], val[64], *res; const char *args[3];
    snprintf(idbuf,sizeof(idbuf),"%d",up_id(L)); snprintf(val,sizeof(val),"%.17g",(double)lua_tonumberx(L,2,NULL));
    args[0]="player.setHealth"; args[1]=idbuf; args[2]=val; res=rpc(3,args); free(res); lua_pushboolean(L,1); return 1;
}
static int player_get_position(lua_State *L) {
    char idbuf[32], *res; const char *args[2]; char *p,*q,*r;
    snprintf(idbuf,sizeof(idbuf),"%d",up_id(L)); args[0]="player.getPosition"; args[1]=idbuf; res=rpc(2,args);
    p=strtok(res,","); q=strtok(NULL,","); r=strtok(NULL,",");
    lua_createtable(L,0,3);
    lua_pushnumber(L,p?atof(p):0); lua_setfield(L,-2,"x");
    lua_pushnumber(L,q?atof(q):0); lua_setfield(L,-2,"y");
    lua_pushnumber(L,r?atof(r):0); lua_setfield(L,-2,"z");
    free(res); return 1;
}
static int player_teleport(lua_State *L) {
    char idbuf[32], x[64],y[64],z[64],*res; const char *args[5];
    snprintf(idbuf,sizeof(idbuf),"%d",up_id(L)); snprintf(x,sizeof(x),"%.17g",lua_tonumberx(L,2,NULL));
    snprintf(y,sizeof(y),"%.17g",lua_tonumberx(L,3,NULL)); snprintf(z,sizeof(z),"%.17g",lua_tonumberx(L,4,NULL));
    args[0]="player.teleport"; args[1]=idbuf; args[2]=x; args[3]=y; args[4]=z; res=rpc(5,args); free(res); lua_pushboolean(L,1); return 1;
}
static int player_has_permission(lua_State *L) {
    char idbuf[32], *permission=arg_string(L,2), *res; const char *args[3];
    snprintf(idbuf,sizeof(idbuf),"%d",up_id(L));
    args[0]="player.hasPermission"; args[1]=idbuf; args[2]=permission;
    res=rpc(3,args); free(permission); lua_pushboolean(L,res && strcmp(res,"1")==0); free(res); return 1;
}

static int sender_send_message(lua_State *L) {
    char *token=arg_string(L,1), *msg=arg_string(L,2), *res; const char *args[3]={"sender.sendMessage",token,msg};
    res=rpc(3,args); free(token); free(msg); lua_pushboolean(L,res && strcmp(res,"1")==0); free(res); return 1;
}
static int sender_get_name(lua_State *L) {
    char *token=arg_string(L,1), *res; const char *args[2]={"sender.getName",token};
    res=rpc(2,args); free(token); lua_pushstring(L,res); free(res); return 1;
}
static int sender_has_permission(lua_State *L) {
    char *token=arg_string(L,1), *permission=arg_string(L,2), *res; const char *args[3]={"sender.hasPermission",token,permission};
    res=rpc(3,args); free(token); free(permission); lua_pushboolean(L,res && strcmp(res,"1")==0); free(res); return 1;
}

static int server_log(lua_State *L) {
    char *msg=arg_string(L,1); const char *args[2]={"server.log",msg}; char *res=rpc(2,args); free(msg); free(res); lua_pushboolean(L,1); return 1;
}
static int server_broadcast(lua_State *L) {
    char *msg=arg_string(L,1); const char *args[2]={"server.broadcast",msg}; char *res=rpc(2,args); free(msg); free(res); lua_pushboolean(L,1); return 1;
}
static int server_online_count(lua_State *L) {
    const char *args[1]={"server.getOnlinePlayerCount"}; char *res=rpc(1,args); lua_pushinteger(L,atoll(res)); free(res); return 1;
}
static int server_get_player(lua_State *L) {
    char *name=arg_string(L,1), *res; const char *args[2]={"server.getPlayer",name};
    res=rpc(2,args); free(name);
    if(res[0]==0 || strcmp(res,"0")==0){ free(res); lua_pushnil(L); return 1; }
    push_player(L,atoi(res)); free(res); return 1;
}
static int server_get_online(lua_State *L) {
    const char *args[1]={"server.getOnlinePlayers"}; char *res=rpc(1,args); char *save=NULL,*tok;
    lua_createtable(L,0,4);
    tok=strtok_r(res,",",&save); int idx=1;
    while(tok){ push_player(L,atoi(tok)); lua_seti(L,-2,idx++); tok=strtok_r(NULL,",",&save); }
    free(res); return 1;
}
static int server_dispatch(lua_State *L) {
    char *line=arg_string(L,2), sender[32]; int sid=0;
    if(lua_type(L,1)==LUA_TTABLE) sid=get_self_id(L);
    snprintf(sender,sizeof(sender),"%d",sid);
    { const char *args[3]={"server.dispatchCommand",sender,line}; char *res=rpc(3,args); lua_pushboolean(L,res && strcmp(res,"1")==0); free(line); free(res); return 1; }
}
static int config_get(lua_State *L) {
    char *key=arg_string(L,1); const char *args[2]={"config.get",key}; char *res=rpc(2,args); free(key);
    /* Result is a typed string: s:value, n:value, b:0/1, nil */
    if(strncmp(res,"s:",2)==0) lua_pushstring(L,res+2);
    else if(strncmp(res,"n:",2)==0) lua_pushnumber(L,atof(res+2));
    else if(strncmp(res,"b:",2)==0) lua_pushboolean(L,res[2]=='1');
    else lua_pushnil(L);
    free(res); return 1;
}
static char *lua_value_string(lua_State *L, int idx) {
    return arg_string(L, idx);
}
static int config_set(lua_State *L) {
    char *key=lua_value_string(L,1), *value=lua_value_string(L,2), *res; const char *args[3]={"config.set",key,value};
    res=rpc(3,args); free(key); free(value); lua_pushboolean(L,res && strcmp(res,"1")==0); free(res); return 1;
}
static int config_save(lua_State *L) {
    const char *args[1]={"config.save"}; char *res=rpc(1,args); lua_pushboolean(L,res && strcmp(res,"1")==0); free(res); return 1;
}
static int config_reload(lua_State *L) {
    const char *args[1]={"config.reload"}; char *res=rpc(1,args); lua_pushboolean(L,res && strcmp(res,"1")==0); free(res); return 1;
}
static int server_get_world(lua_State *L) {
    char *name=arg_string(L,1), *res; const char *args[2]={"server.getWorld",name}; res=rpc(2,args); free(name);
    if(!res || res[0]==0){ free(res); lua_pushnil(L); return 1; }
    lua_createtable(L,0,4); lua_pushstring(L,res); lua_setfield(L,-2,"__world_token"); free(res); return 1;
}
static int server_get_default_world(lua_State *L) {
    const char *args[1]={"server.getDefaultWorld"}; char *res=rpc(1,args);
    if(!res || res[0]==0){ free(res); lua_pushnil(L); return 1; }
    lua_createtable(L,0,4); lua_pushstring(L,res); lua_setfield(L,-2,"__world_token"); free(res); return 1;
}
static int server_get_data_path(lua_State *L) {
    const char *args[1]={"server.getDataPath"}; char *res=rpc(1,args); lua_pushstring(L,res); free(res); return 1;
}
static int server_get_name(lua_State *L) {
    const char *args[1]={"server.getName"}; char *res=rpc(1,args); lua_pushstring(L,res); free(res); return 1;
}
static int server_get_port(lua_State *L) {
    const char *args[1]={"server.getPort"}; char *res=rpc(1,args); lua_pushinteger(L,atoll(res)); free(res); return 1;
}
static int server_get_motd(lua_State *L) {
    const char *args[1]={"server.getMotd"}; char *res=rpc(1,args); lua_pushstring(L,res); free(res); return 1;
}
static int plugin_get_name(lua_State *L) {
    const char *args[1]={"plugin.getName"}; char *res=rpc(1,args); lua_pushstring(L,res); free(res); return 1;
}
static int plugin_get_data_folder(lua_State *L) {
    const char *args[1]={"plugin.getDataFolder"}; char *res=rpc(1,args); lua_pushstring(L,res); free(res); return 1;
}

static int get_world_token(lua_State *L, int idx, char *buf, size_t size) {
    if(lua_type(L,idx)!=LUA_TTABLE){ buf[0]=0; return 0; }
    lua_getfield(L,idx,"__world_token");
    const char *s=lua_tolstring(L,-1,NULL);
    if(!s){ lua_settop(L,-2); buf[0]=0; return 0; }
    snprintf(buf,size,"%s",s); lua_settop(L,-2); return 1;
}
static int world_get_name(lua_State *L) {
    char token[1024], *res; if(!get_world_token(L,1,token,sizeof(token))) {lua_pushstring(L,""); return 1;}
    const char *args[2]={"world.getName",token}; res=rpc(2,args); lua_pushstring(L,res); free(res); return 1;
}
static int world_get_players(lua_State *L) {
    char token[1024], *res, *save=NULL, *tok; int index=1;
    if(!get_world_token(L,1,token,sizeof(token))){ lua_createtable(L,0,0); return 1; }
    { const char *args[2]={"world.getPlayers",token}; res=rpc(2,args); }
    lua_createtable(L,0,0); tok=strtok_r(res,",",&save); while(tok){ push_player(L,atoi(tok)); lua_seti(L,-2,index++); tok=strtok_r(NULL,",",&save); } free(res); return 1;
}
static int world_get_block(lua_State *L) {
    char token[1024], x[64],y[64],z[64], *res; const char *args[5];
    if(!get_world_token(L,1,token,sizeof(token))){ lua_pushnil(L); return 1; }
    snprintf(x,sizeof(x),"%.17g",lua_tonumberx(L,2,NULL)); snprintf(y,sizeof(y),"%.17g",lua_tonumberx(L,3,NULL)); snprintf(z,sizeof(z),"%.17g",lua_tonumberx(L,4,NULL));
    args[0]="world.getBlock";args[1]=token;args[2]=x;args[3]=y;args[4]=z; res=rpc(5,args);
    /* Blocks cross the bridge as JSON; expose a small read-only Lua table. */
    lua_createtable(L,0,0); lua_pushstring(L,res); lua_setfield(L,-2,"json"); free(res); return 1;
}
static int world_set_block(lua_State *L) {
    char token[1024],x[64],y[64],z[64],id[32],damage[32],*res; const char *args[7];
    if(!get_world_token(L,1,token,sizeof(token))){ lua_pushboolean(L,0); return 1; }
    snprintf(x,sizeof(x),"%.17g",lua_tonumberx(L,2,NULL)); snprintf(y,sizeof(y),"%.17g",lua_tonumberx(L,3,NULL)); snprintf(z,sizeof(z),"%.17g",lua_tonumberx(L,4,NULL));
    snprintf(id,sizeof(id),"%lld",(long long)lua_tointegerx(L,5,NULL)); snprintf(damage,sizeof(damage),"%lld",(long long)lua_tointegerx(L,6,NULL));
    args[0]="world.setBlock";args[1]=token;args[2]=x;args[3]=y;args[4]=z;args[5]=id;args[6]=damage; res=rpc(7,args); lua_pushboolean(L,res && strcmp(res,"1")==0); free(res); return 1;
}
static int entity_get_name(lua_State *L) {
    char id[32], *res; const char *args[2]={"entity.getName",NULL}; snprintf(id,sizeof(id),"%lld",(long long)lua_tointegerx(L,1,NULL)); args[1]=id; res=rpc(2,args); lua_pushstring(L,res); free(res); return 1;
}
static int entity_get_position(lua_State *L) {
    char id[32], *res,*p,*q,*r; const char *args[2]={"entity.getPosition",NULL}; snprintf(id,sizeof(id),"%lld",(long long)lua_tointegerx(L,1,NULL)); args[1]=id; res=rpc(2,args); p=strtok(res,","); q=strtok(NULL,","); r=strtok(NULL,","); lua_createtable(L,0,0); lua_pushnumber(L,p?atof(p):0); lua_setfield(L,-2,"x"); lua_pushnumber(L,q?atof(q):0); lua_setfield(L,-2,"y"); lua_pushnumber(L,r?atof(r):0); lua_setfield(L,-2,"z"); free(res); return 1;
}
static int event_set_cancelled(lua_State *L) {
    int token=up_id(L); char tok[32], val[8]; const char *args[3];
    snprintf(tok,sizeof(tok),"%d",token); snprintf(val,sizeof(val),"%d",lua_toboolean(L,2)?1:0);
    args[0]="event.setCancelled"; args[1]=tok; args[2]=val; { char *res=rpc(3,args); free(res); }
    return 0;
}

static int events_on(lua_State *L) {
    char *name=arg_string(L,1); int ref, id; char idbuf[32], priority[32], ignore[8]; const char *args[4];
    if(lua_type(L,2)!=LUA_TFUNCTION){ free(name); luaL_error(L,"Events.on(name, callback [, priority [, ignoreCancelled]]) requires a function"); return 0; }
    lua_pushvalue(L,2); ref=luaL_ref(L,LUA_REGISTRYINDEX); id=cb_add(L,ref,"event");
    snprintf(idbuf,sizeof(idbuf),"%d",id);
    { int isnum=0; lua_Integer p=lua_tointegerx(L,3,&isnum); snprintf(priority,sizeof(priority),"%lld",(long long)(isnum ? p : 3)); }
    snprintf(ignore,sizeof(ignore),"%d",lua_toboolean(L,4)?1:0);
    args[0]=name; args[1]=idbuf; args[2]=priority; args[3]=ignore; send_line("REGISTER_EVENT",4,args);
    free(name); lua_pushinteger(L,id); return 1;
}

static char *lua_aliases(lua_State *L, int idx) {
    size_t cap=128,len=0; char *out=(char*)malloc(cap); int first=1;
    if(lua_type(L,idx)==LUA_TSTRING) return arg_string(L,idx);
    if(lua_type(L,idx)!=LUA_TTABLE){ out[0]=0; return out; }
    lua_pushnil(L);
    while(lua_next(L,idx < 0 ? idx-1 : idx)!=0){
        const char *v=lua_tolstring(L,-1,NULL);
        if(v){
            size_t vl=strlen(v);
            if(len+vl+2>=cap){ cap=(len+vl+2)*2; out=(char*)realloc(out,cap); }
            if(!first) out[len++]='|';
            memcpy(out+len,v,vl); len+=vl; first=0;
        }
        lua_settop(L,-2);
    }
    out[len]=0; return out;
}

static int commands_register(lua_State *L) {
    char *name=arg_string(L,1), *description=arg_string(L,3), *usage=arg_string(L,4), *permission=arg_string(L,5), *aliases=lua_aliases(L,6);
    int ref,id; char idbuf[32]; const char *args[6];
    if(lua_type(L,2)!=LUA_TFUNCTION){ free(name);free(description);free(usage);free(permission);free(aliases);luaL_error(L,"Commands.register(name, callback, ...) requires a function");return 0; }
    lua_pushvalue(L,2); ref=luaL_ref(L,LUA_REGISTRYINDEX); id=cb_add(L,ref,"command"); snprintf(idbuf,sizeof(idbuf),"%d",id);
    args[0]=name;args[1]=idbuf;args[2]=description;args[3]=usage;args[4]=permission;args[5]=aliases;
    send_line("REGISTER_COMMAND",6,args);
    free(name);free(description);free(usage);free(permission);free(aliases);lua_pushinteger(L,id);return 1;
}
/* helper used by Scheduler wrappers, expects mode string, delay, period */
static int scheduler_do(lua_State *L, const char *mode) {
    lua_CFunction fn=NULL; (void)fn;
    int ref,id; char *delay=arg_string(L,1),*period=arg_string(L,2),idbuf[32]; const char *args[4];
    if(lua_type(L,3)!=LUA_TFUNCTION){ free(delay);free(period);luaL_error(L,"Scheduler callback must be a function");return 0; }
    lua_pushvalue(L,3); ref=luaL_ref(L,LUA_REGISTRYINDEX); id=cb_add(L,ref,"timer"); snprintf(idbuf,sizeof(idbuf),"%d",id);
    args[0]=idbuf;args[1]=mode;args[2]=delay;args[3]=period;send_line("REGISTER_TIMER",4,args);
    free(delay);free(period);lua_pushinteger(L,id);return 1;
}
static int scheduler_later(lua_State *L) {
    int ref,id; char d[64],idbuf[32]; const char *args[4]={"","","",""};
    if(lua_type(L,2)!=LUA_TFUNCTION){ luaL_error(L,"Scheduler.runLater(delay, callback) requires a function"); return 0; }
    snprintf(d,sizeof(d),"%lld",(long long)lua_tointegerx(L,1,NULL));
    lua_pushvalue(L,2); ref=luaL_ref(L,LUA_REGISTRYINDEX); id=cb_add(L,ref,"timer"); snprintf(idbuf,sizeof(idbuf),"%d",id);
    args[0]=idbuf; args[1]="later"; args[2]=d; args[3]="-1"; send_line("REGISTER_TIMER",4,args);
    { int ok=0; char *res=wait_result(&ok); if(!ok){ luaL_error(L,"scheduler registration failed: %s",res); free(res); return 0; } lua_pushinteger(L,atoll(res)); free(res); return 1; }
}
static int scheduler_repeating(lua_State *L) {
    int ref,id; char p[64],idbuf[32]; const char *args[4]={"","","",""};
    if(lua_type(L,2)!=LUA_TFUNCTION){ luaL_error(L,"Scheduler.runRepeating(period, callback) requires a function"); return 0; }
    snprintf(p,sizeof(p),"%lld",(long long)lua_tointegerx(L,1,NULL));
    lua_pushvalue(L,2); ref=luaL_ref(L,LUA_REGISTRYINDEX); id=cb_add(L,ref,"timer"); snprintf(idbuf,sizeof(idbuf),"%d",id);
    args[0]=idbuf; args[1]="repeat"; args[2]="-1"; args[3]=p; send_line("REGISTER_TIMER",4,args);
    { int ok=0; char *res=wait_result(&ok); if(!ok){ luaL_error(L,"scheduler registration failed: %s",res); free(res); return 0; } lua_pushinteger(L,atoll(res)); free(res); return 1; }
}
static int scheduler_delayed_repeating(lua_State *L) {
    int ref,id; char d[64],p[64],idbuf[32]; const char *args[4]={"","","",""};
    if(lua_type(L,3)!=LUA_TFUNCTION){ luaL_error(L,"Scheduler.runDelayedRepeating(delay, period, callback) requires a function"); return 0; }
    snprintf(d,sizeof(d),"%lld",(long long)lua_tointegerx(L,1,NULL)); snprintf(p,sizeof(p),"%lld",(long long)lua_tointegerx(L,2,NULL));
    lua_pushvalue(L,3); ref=luaL_ref(L,LUA_REGISTRYINDEX); id=cb_add(L,ref,"timer"); snprintf(idbuf,sizeof(idbuf),"%d",id);
    args[0]=idbuf; args[1]="delay_repeat"; args[2]=d; args[3]=p; send_line("REGISTER_TIMER",4,args);
    { int ok=0; char *res=wait_result(&ok); if(!ok){ luaL_error(L,"scheduler registration failed: %s",res); free(res); return 0; } lua_pushinteger(L,atoll(res)); free(res); return 1; }
}

static int scheduler_cancel(lua_State *L) {
    char id[32]; snprintf(id,sizeof(id),"%lld",(long long)lua_tointegerx(L,1,NULL)); const char *args[2]={"scheduler.cancel",id}; char *res=rpc(2,args); free(res); return 0;
}

static void make_event_table(lua_State *L, const char *eventName, int argc, char **argv, int token) {
    int i;
    lua_createtable(L,0,argc/2+5);
    lua_pushstring(L,eventName); lua_setfield(L,-2,"name");
    lua_pushinteger(L,token); lua_pushcclosure(L,event_set_cancelled,1); lua_setfield(L,-2,"setCancelled");
    {
        int cancelled = 0;
        for(i=0;i+1<argc;i+=2){
            if(strcmp(argv[i],"cancelled") == 0 && strcmp(argv[i+1],"1") == 0){
                cancelled = 1;
                break;
            }
        }
        lua_pushboolean(L,cancelled); lua_setfield(L,-2,"cancelled");
    }
    for(i=0;i+1<argc;i+=2){
        char *key=dec(argv[i]), *val=dec(argv[i+1]);
        if(strncmp(val,"player:",7)==0){
            make_player(L,atoi(val+7)); lua_setfield(L,-2,key);
        }else{
            lua_pushstring(L,val); lua_setfield(L,-2,key);
        }
        free(key);free(val);
    }
}

static void set_api_table(lua_State *L) {
    /* Server */
    lua_createtable(L,0,16);
    lua_pushcfunction(L,server_log); lua_setfield(L,-2,"log");
    lua_pushcfunction(L,server_broadcast); lua_setfield(L,-2,"broadcast");
    lua_pushcfunction(L,server_online_count); lua_setfield(L,-2,"getOnlinePlayerCount");
    lua_pushcfunction(L,server_get_player); lua_setfield(L,-2,"getPlayer");
    lua_pushcfunction(L,server_get_online); lua_setfield(L,-2,"getOnlinePlayers");
    lua_pushcfunction(L,server_get_world); lua_setfield(L,-2,"getWorld");
    lua_pushcfunction(L,server_get_default_world); lua_setfield(L,-2,"getDefaultWorld");
    lua_pushcfunction(L,server_get_data_path); lua_setfield(L,-2,"getDataPath");
    lua_pushcfunction(L,server_get_name); lua_setfield(L,-2,"getName");
    lua_pushcfunction(L,server_get_port); lua_setfield(L,-2,"getPort");
    lua_pushcfunction(L,server_get_motd); lua_setfield(L,-2,"getMotd");
    lua_pushcfunction(L,server_dispatch); lua_setfield(L,-2,"dispatchCommand");
    lua_setglobal(L,"Server");

    lua_createtable(L,0,8); lua_pushcfunction(L,config_get); lua_setfield(L,-2,"get"); lua_pushcfunction(L,config_set); lua_setfield(L,-2,"set"); lua_pushcfunction(L,config_save); lua_setfield(L,-2,"save"); lua_pushcfunction(L,config_reload); lua_setfield(L,-2,"reload"); lua_setglobal(L,"Config");

    lua_createtable(L,0,4); lua_pushcfunction(L,events_on); lua_setfield(L,-2,"on"); lua_setglobal(L,"Events");

    lua_createtable(L,0,8);
    lua_pushcfunction(L,scheduler_later); lua_setfield(L,-2,"runLater");
    lua_pushcfunction(L,scheduler_repeating); lua_setfield(L,-2,"runRepeating");
    lua_pushcfunction(L,scheduler_delayed_repeating); lua_setfield(L,-2,"runDelayedRepeating");
    lua_pushcfunction(L,scheduler_cancel); lua_setfield(L,-2,"cancel");
    lua_setglobal(L,"Scheduler");

    lua_createtable(L,0,8); lua_pushcfunction(L,commands_register); lua_setfield(L,-2,"register"); lua_setglobal(L,"Commands");

    lua_createtable(L,0,8); lua_pushcfunction(L,world_get_name); lua_setfield(L,-2,"getName"); lua_pushcfunction(L,world_get_players); lua_setfield(L,-2,"getPlayers"); lua_pushcfunction(L,world_get_block); lua_setfield(L,-2,"getBlock"); lua_pushcfunction(L,world_set_block); lua_setfield(L,-2,"setBlock"); lua_setglobal(L,"World");

    lua_createtable(L,0,4); lua_pushcfunction(L,entity_get_name); lua_setfield(L,-2,"getName"); lua_pushcfunction(L,entity_get_position); lua_setfield(L,-2,"getPosition"); lua_setglobal(L,"Entity");

    lua_createtable(L,0,6); lua_pushcfunction(L,plugin_get_name); lua_setfield(L,-2,"getName"); lua_pushcfunction(L,plugin_get_data_folder); lua_setfield(L,-2,"getDataFolder"); lua_setglobal(L,"Plugin");

    lua_createtable(L,0,6); lua_pushcfunction(L,sender_send_message); lua_setfield(L,-2,"sendMessage"); lua_pushcfunction(L,sender_get_name); lua_setfield(L,-2,"getName"); lua_pushcfunction(L,sender_has_permission); lua_setfield(L,-2,"hasPermission"); lua_setglobal(L,"Sender");

    /* Block dangerous stdlib modules. */
    lua_pushnil(L); lua_setglobal(L,"io");
    lua_pushnil(L); lua_setglobal(L,"os");
    lua_pushnil(L); lua_setglobal(L,"debug");
    lua_pushnil(L); lua_setglobal(L,"package");
    lua_pushnil(L); lua_setglobal(L,"require");
    lua_pushnil(L); lua_setglobal(L,"dofile");
    lua_pushnil(L); lua_setglobal(L,"loadfile");
    lua_pushnil(L); lua_setglobal(L,"load");
}

static void push_command_sender(lua_State *L) {
    lua_getfield(L,1,"sender");
    if(lua_type(L,-1)==LUA_TTABLE){
        return;
    }
    {
        const char *s=lua_tolstring(L,-1,NULL);
        if(s && strncmp(s,"player:",7)==0){
            int id=atoi(s+7); lua_settop(L,-2); make_player(L,id);
        }else{
            lua_settop(L,-2); lua_pushstring(L,"console");
        }
    }
}
static void push_command_args(lua_State *L) {
    lua_getfield(L,1,"args");
    const char *s=lua_tolstring(L,-1,NULL); int idx=1;
    lua_createtable(L,0,8);
    if(s){
        char *copy=strdup(s), *p=copy, *q;
        while((q=strchr(p,1))!=NULL){
            *q=0; lua_pushstring(L,p); lua_seti(L,-2,idx++); p=q+1;
        }
        if(*p!='\0' || idx==1){ lua_pushstring(L,p); lua_seti(L,-2,idx++); }
        free(copy);
    }
    /* Drop the source string, keep only the new argument table. */
    lua_rotate(L, -2, 1);
    lua_settop(L, -2);
}

static int invoke(lua_State *L, int cbid, const char *eventName, int pairCount, char **pairs) {
    Callback *c=cb_find(cbid); char line[65536]; int status; char *args[128]; int argc=pairCount*2, i;
    if(!c) return -1;
    if(argc>128) argc=128;
    make_event_table(L,eventName,argc,pairs,cbid);
    lua_rawgeti(L,LUA_REGISTRYINDEX,c->ref);
    if(strcmp(eventName,"command")==0){
        push_command_sender(L);
        push_command_args(L);
        /* Arrange stack as [callback, sender, args] for lua_pcall(). */
        lua_rotate(L,1,-1);
        lua_settop(L,-2);
        status=lua_pcallk(L,2,1,0,0,NULL);
    }else{
        lua_pushvalue(L,-2);
        status=lua_pcallk(L,1,1,0,0,NULL);
    }
    if(status!=LUA_OK){
        const char *e=lua_tolstring(L,-1,NULL); char *ee=enc(e?e:"Lua error");
        printf("CALLBACK_ERROR\t%d\t%s\n",cbid,ee); fflush(stdout); free(ee);
        lua_settop(L,0); return -1;
    }
    {
        int result=1;
        if(lua_type(L,-1)==LUA_TBOOLEAN) result=lua_toboolean(L,-1)?1:0;
        snprintf(line,sizeof(line),"RETURN\t%d\t%d",cbid,result); send_line1(line);
    }
    lua_settop(L,0);
    return 0;
}


static int call_global(lua_State *L, const char *name) {
    int status;
    lua_getglobal(L,name);
    if(lua_type(L,-1)!=LUA_TFUNCTION){
        lua_settop(L,0);
        return 0;
    }
    status=lua_pcallk(L,0,0,0,0,NULL);
    if(status!=LUA_OK){
        const char *e=lua_tolstring(L,-1,NULL); char *ee=enc(e?e:"Lua callback failed");
        const char *args[2]={"0",ee}; send_line("CALLBACK_ERROR",2,args); free(ee);
        lua_settop(L,0);
        return -1;
    }
    return 1;
}

int main(int argc, char **argv) {
    lua_State *L; char line[65536];
    /* Ctrl+C is intended for the server parent process. Keep the Lua
       child alive until PHP explicitly sends SHUTDOWN during onDisable. */
    signal(SIGINT, SIG_IGN);
    signal(SIGTERM, SIG_IGN);
    signal(SIGPIPE, SIG_IGN);
    if(argc<2) die("usage: external-lua-host <main.lua>");
    { const char *env=getenv("EXTERNAL_LUA_TIMEOUT_MS"); if(env && *env){ long v=strtol(env,NULL,10); if(v>=50 && v<=60000) bridge_timeout_ms=v; } }
    L=luaL_newstate(); if(!L) die("unable to create Lua state");
    luaL_openlibs(L); set_api_table(L);
    if(luaL_loadfilex(L,argv[1],NULL)!=LUA_OK){
        const char *e=lua_tolstring(L,-1,NULL);
        const char *a[2]={"main",e?e:"load error"}; send_line("ERROR",2,a); lua_close(L); return 1;
    }
    if(lua_pcallk(L,0,0,0,0,NULL)!=LUA_OK){
        const char *e=lua_tolstring(L,-1,NULL);
        const char *a[2]={"main",e?e:"runtime error"}; send_line("ERROR",2,a); lua_close(L); return 1;
    }
    /* Match PluginBase semantics: source is loaded first, then onLoad() is
       invoked before PHP enables the plugin and calls onEnable(). */
    if(call_global(L,"onLoad") < 0){
        lua_close(L); return 1;
    }
    send_line1("READY");
    while(fgets(line,sizeof(line),stdin)){
        char *save=NULL,*tag=strtok_r(line,"\t\r\n",&save),*a=strtok_r(NULL,"\t\r\n",&save),*ev=strtok_r(NULL,"\t\r\n",&save);
        if(!tag) continue;
        if(strcmp(tag,"INVOKE")==0 && a && ev){
            int cbid=atoi(a), pair=0; char *pairs[128]; char *x;
            while((x=strtok_r(NULL,"\t\r\n",&save))!=NULL && pair<128){ pairs[pair++]=x; }
            { char *dev=dec(ev); invoke(L,cbid,dev,pair/2,pairs); free(dev); }
        }else if(strcmp(tag,"CALL_FUNCTION")==0 && a){
            char *fn=dec(a); int result=call_global(L,fn); free(fn);
            if(result>=0){ const char *args[2]={"1",""}; send_line("FUNCTION_RETURN",2,args); }
        }else if(strcmp(tag,"SHUTDOWN")==0) break;
    }
    lua_close(L);
    return 0;
}

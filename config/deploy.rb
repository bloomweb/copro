# INITIAL CONFIGURATION
set :application, "copro.bloomweb.co"
set :export, :remote_cache
set :keep_releases, 5
set :cakephp_app_path, "app"
set :cakephp_core_path, "cake"
#default_run_options[:pty] = true # Para pedir la contraseña de la llave publica de github via consola, sino sale error de llave publica.

# DEPLOYMENT DIRECTORY STRUCTURE
set :deploy_to, "/home/embalao/copro.bloomweb.co"

# USER & PASSWORD
set :user, 'embalao'
set :password, 'Luci@na2012'

# ROLES
role :app, "copro.bloomweb.co"
role :web, "copro.bloomweb.co"
role :db, "copro.bloomweb.co", :primary => true

# VERSION TRACKER INFORMATION
set :scm, :git
set :use_sudo, false
set :repository,  "git@github.com:bloomweb/copro.git"
set :branch, "master"

# TASKS
namespace :deploy do
  
  task :start do ; end
  
  task :stop do ; end
  
  task :restart, :roles => :app, :except => { :no_release => true } do
    run "cp /home/embalao/copro.bloomweb.co/current/source/. /home/embalao/copro.bloomweb.co/ -R"
    run "chmod 777 /home/embalao/copro.bloomweb.co/app/tmp/ -R"
    run "chmod 777 /home/embalao/copro.bloomweb.co/app/webroot/img/uploads/ -R"
    run "chmod 777 /home/embalao/copro.bloomweb.co/app/webroot/files/uploads/ -R"
  end
  
end
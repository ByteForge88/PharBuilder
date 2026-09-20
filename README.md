# Description
I made this so you can compile BetterAltay plugins with ease! (old description)

Recently PocketMine-MP has stop updating along side their website Poggit. Poggit was a way developers can compile their plugins and not worry about libs/virions. With that gone I made a compiler that works exaclty like poggits CI. Enjoy and have fun coding!

# Usage
1. Download the build.php and move it to your plugins source (**NOT** inside the "plugins" folder aka the server plugins folder)
2. Go to your terminal and do "cd /your/plugins/path/"
3. Make a file called "dependencies.yml" (Look below for an example)
4. Run the command "php -d phar.readonly=0 build.php"
5. The built plugin will appear in the same folder if built successfully

# Example dependencies.yml
```yml
dependency:
  - url: https://github.com/CortexPE/Commando
    branch: master
    version: 3.3.0

  - url: https://github.com/Example/ExampleRepo
    branch: main
    version: 1.0.0
```
## 0.9.4 (August 31, 2021)
  - [BUGFIX] replaced continue inside switch with break

## 0.9.3 (August 19, 2021)
  - [BUGFIX] removed exceptions in loops (oops)

## 0.9.2 (July 30, 2021)
  - [BUGFIX] added a try catch block to not break the loop if something goes wrong for one item

## 0.9.1 (July 05, 2021)
  - [BUGFIX] the default action in the standard is "add"

## 0.9.0 (February 26, 2021)
  - Merge pull request #3 from ujamii/update-v0.9
  - [FEATURE] every imported asset is now taggen with "openimmo" to simplify cleanup
  - [DOC] added housekeeping section to the readme
  - [FEATURE] added new command "openimmo:clear"
  - [TASK] update library to v0.9
  - Merge pull request #2 from ujamii/refactoring
  - [BUGFIX] importing assets may cause a crash, so assets are now imported differently (not every asset is an image)
  - [TASK] separated the command controller into two classes, one for each single command

## 0.8.0 (February 22, 2021)
  - Merge pull request #1 from ujamii/task/update-library
  - [FEATURE] as the ujamii/openimmo library now parses sub fields of type Feld[] correctly, the node type Feld is new in this package
  - [TASK] as "boolean" fields were renamed to "bool" type in the library, we have to take care of this here as well
  - [FEATURE] as the ujamii/openimmo library now parses sub fields of type Feld[] correctly, the node types Bewertung and UserDefinedExtend have been rebuilt
  - [TASK] raised library constraint

## 0.6.2 (February 12, 2021)
  - [BUGFIX] first import of new nodes with several levels of children failed because no collection node has been persisted

## 0.6.1 (December 11, 2020)
  - [BUGFIX] as one openimmo xml may not contain all real estate objects, we can not hide nodes which were not included

## 0.6.0 (December 08, 2020)
  - [TASK] solved some code improvement hints
  - [FEATURE] added new option to log imported xml string
  - [DOC] updated TODOs

## 0.5.0 (December 03, 2020)
  - [BUGFIX] existing nodes now get hidden if they are not present in the import data.
  - [BUGFIX] existing nodes may have properties which are no longer present in the next import. This lead to wrong data. So now, existing properties are removed if they are not present in the import data.
  - [BUGFIX] an existing directory with the same name as the zip file but different content crashed the import. So if an existing dir of that name is found now, it is deleted before import. 
  - [BUGFIX] existing nodes are now hidden, if they are not present in the imported xml file 
  - [TASK] make usage of NEOS' Files class to delete directory recursively instead of own code 
  - [TASK] deprecated getPrimaryChildNode is no longer used

## 0.4.0 (November 26, 2020)
  - [FEATURE] added feature to hide address from url

## 0.3.0 (November 26, 2020)
  - [BUGFIX] updated to ujamii/openimmo 0.6 to fix missing values from mixed xml fields

## 0.2.3 (November 20, 2020)
  - [BUGFIX] array of string properties are now handled as strings correctly
  - [BUGFIX] string properties are not a child node type

## 0.2.2 (November 20, 2020)
  - [BUGFIX] using an absolute path failed during image import

## 0.2.1 (November 20, 2020)
  - [BUGFIX] using an absolute path failed during archive extraction

## 0.2.0 (September 14, 2020)
  - [TASK] regenerated package files
  - [TEST] renamed package

## 0.1.1 (August 31, 2020)
  - [DOC] added license and author information

## 0.1.0 (August 31, 2020)
  - [DOC] added some documentation
  - [FEATURE] imported files are deleted after import 
  - [FEATURE] node labels in content tree can now be customized 
  - [BUGFIX] DateTime properties are now handled correctly in output 
  - [BUGFIX] multiple nodetypes on the same nesting level are now imported correctly
  - [TASK] added more shiny icons
  - [FEATURE] added first working importer
  - [TASK] added reloadIfChanged for all properties 
  - [FEATURE] added NEOS based assets instead of base64 string properties
  - [FEATURE] NodeTypes have configurable icons now
  - [BUGFIX] fixed wrong Content vs. Document Naming of yaml files 
  - [FEATURE] NodeTypes have configurable icons now
  - [FEATURE] added fusion rendering molecules
  - [FEATURE] only root object "Immobilie" is a document, everything else is content 
  - [FEATURE] node types are nested into collections now 
  - [FEATURE] added some basic fusion code for rendering
  - [FEATURE] added fusion prototypes and properties to yaml
  - [TASK] switched from reflectionservice to oop package
  - [TASK] initial commit


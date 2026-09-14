#EXCITING WORK IN PYTHON!

#install libraries in python using pip
#GUI (graphical user interface); we will use Tkinter and pillow
#pip install tkinter
#pip install pillow

#there is so much that can be improved here! The corresponding assignment is
# your opportunity to be creative and incorporate HCI principles

#we need to import tkinter (this is one way to write an import instruction)
from tkinter import *

#deal with images (you don't have to import all of a given library!)
from PIL import ImageTk, Image

#you need a Tk() root object to create your window, and put things inside of it
root = Tk()

#perhaps we want to set the size of the window and place things in the window
#learn by playing around!
#first coordinate is the width, x, the second is the height, y
root.geometry("500x650")

#perhaps we want a title for our window
root.title('Tell us everything')

#let's put a label on the screen
label_1 = Label(root, text="Personal Information", width=30, font=('bold', 20))
#put it on the screen
label_1.place(x=0, y=30)

#get the user's name
label_2 = Label(root, text="Name:", width=20, font=('bold', 12))
#put it on the screen
label_2.place(x=0, y=100)

#get a box for user input
#we'll want to store that information
name = StringVar()
#create an Entry widget...attaching it to the root Tk() object, and binding the variable
#name to its contents.
myName = Entry(root, textvariable=name)
#set the width to 30
myName.configure(width=30)
#place the box on the screen
myName.place(x=130, y=105)

#now, let's get their e-mail address
label_3 = Label(root, text="E-mail:", width=20, font=('bold', 12))
#put it on the screen
label_3.place(x=0, y=150)

#get a box for user input
#we'll want to store that information
email = StringVar()
#create an Entry widget...attaching it to the root Tk() object, and binding the variable
#name to its contents.
myEmail = Entry(root, textvariable=email)
#set the width to 30
myEmail.configure(width=30)
#place the box on the screen
myEmail.place(x=130, y=155)

#here is a global variable, and we will reference it in a function
count = 1

#need a submitPressed function to associate with the button
def submitPressed():
    #reference the global variable count (let python know that count is a global variable,
    #not a local variable.  Every time i press the button, the counter will increment by 1
    global count
    count = count + 1
    
    #this is how you retrieve the contents from a StringVar or an IntVar
    #in this case, the contents of the major StringVar are strings...
    myMajor = major.get()
    labelVariable.set("You pressed the button " + str(count) + " times, and are a " + myMajor + " major.")

#it would be great to have a submit button: create it and put it on the screen in one line of code
Button(root, text='Submit', width=20, bg='black', fg='white', command=submitPressed).place(x=100, y = 500)

#let's put radio buttons on the screen: these toggle; can't select more than one
year = IntVar()

#if you choose this option, year will be assigned the value 1...what does padx do?  change its value
#and see what happens...
firstYearButton = Radiobutton(root, text='First Year', padx=5, variable=year, value=1, width=20)
firstYearButton.place(x=0, y=200)

#if you choose this option, year will be assigned the value 2
secondYearButton = Radiobutton(root, text='Second Year', padx=5, variable=year, value=2, width=20)
secondYearButton.place(x=130, y=200)

#what if we have data where multiple options can be chosen?
label_4 = Label(root, text="Languages:", width=20, font=('bold', 12))
#put it on the screen
label_4.place(x=50, y=250)

#let's make check boxes
python = IntVar()
pythonBox = Checkbutton(root, text="Python", variable=python, width=20)
pythonBox.place(x=10, y = 300)

#let's make check boxes
java = IntVar()
javaBox = Checkbutton(root, text="Java", variable=java, width=20)
javaBox.place(x=130, y = 300)

#what if we want a drop down menu?
label_5 = Label(root, text="Major:", width=20, font=('bold', 12))
#put it on the screen
label_5.place(x=50, y=350)

#create the dropdown menu: first, specify your options for the menu
majorList = ['Computer Science', 'Informatics', 'Computer Engineering']

#need a variable to keep track of the choice:
major = StringVar()
#create the optionMenu object, linking it to the variable chosen, and the list of options
#note the *
dropDownList = OptionMenu(root, major, *majorList)
major.set('Select your major')
dropDownList.place(x=130, y=400)

#create a new label, to demonstrate how to change it!
labelVariable = StringVar()
labelVariable.set("hello")
#crete the label:
label_6 = Label(root, textvariable=labelVariable, width=50, font=('bold', 12))
label_6.place(x=0, y=450)


#let's see what we have so far
root.mainloop()



